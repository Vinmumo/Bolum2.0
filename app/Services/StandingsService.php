<?php

namespace App\Services;

use App\Exceptions\ProviderUnavailable;
use App\Models\League;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class StandingsService
{
    public function __construct(private ProviderHttpClient $client) {}

    public function report(League $league): array
    {
        if (! config('football.data_token') || $league->source !== 'football-data' || ! ctype_digit((string) $league->external_id)) {
            throw new ProviderUnavailable('Standings require an imported league and a configured football-data.org connection.');
        }
        $season = config('football.season');
        $query = $season ? ['season' => (int) $season] : [];

        return Cache::lock('football:standings:'.$league->id.':'.($season ?: 'current'), 30)->block(5, fn () => $this->client->get('football-data', 'standings', 'https://api.football-data.org/v4/competitions/'.$league->external_id.'/standings',
            ['X-Auth-Token' => config('football.data_token')], $query, function ($data) use ($league) {
                $validation = Validator::make(is_array($data) ? $data : [], [
                    'competition.id' => 'required|integer', 'season.startDate' => 'required|date_format:Y-m-d',
                    'standings' => 'required|array|max:100', 'standings.*.type' => 'required|string',
                ]);
                if ($validation->fails() || (string) $data['competition']['id'] !== (string) $league->external_id) {
                    throw new ProviderUnavailable('Invalid standings response.');
                }
                $totals = collect($data['standings'])->where('type', 'TOTAL')->values();
                if ($totals->count() !== 1) {
                    throw new ProviderUnavailable('A single league table is required.');
                }
                $rows = $totals[0]['table'] ?? null;
                $validation = Validator::make(['rows' => $rows], [
                    // Tied clubs can share a position; team identities must still be unique.
                    'rows' => 'required|array|max:100', 'rows.*.position' => 'required|integer|min:1',
                    'rows.*.team.id' => 'required|integer|min:1|distinct', 'rows.*.team.name' => 'required|string|max:100',
                    'rows.*.playedGames' => 'required|integer|between:0,1000', 'rows.*.won' => 'required|integer|between:0,1000',
                    'rows.*.draw' => 'required|integer|between:0,1000', 'rows.*.lost' => 'required|integer|between:0,1000',
                    'rows.*.points' => 'required|integer|between:-1000,3000', 'rows.*.goalsFor' => 'required|integer|between:0,10000',
                    'rows.*.goalsAgainst' => 'required|integer|between:0,10000', 'rows.*.goalDifference' => 'required|integer|between:-10000,10000',
                ]);
                if ($validation->fails()) {
                    throw new ProviderUnavailable('Invalid standings rows.');
                }

                return ['league_id' => $league->id, 'league_name' => $league->name, 'season' => (int) substr($data['season']['startDate'], 0, 4),
                    'source' => 'football-data', 'fetched_at' => now()->toIso8601String(),
                    'rows' => collect($rows)->sortBy('position')->map(fn ($r) => [
                        'position' => (int) $r['position'], 'team' => ['external_id' => (string) $r['team']['id'], 'name' => $r['team']['name'], 'crest_url' => Team::normalizeCrestUrl($r['team']['crest'] ?? null)],
                        'played' => (int) $r['playedGames'], 'won' => (int) $r['won'], 'drawn' => (int) $r['draw'], 'lost' => (int) $r['lost'],
                        'goals_for' => (int) $r['goalsFor'], 'goals_against' => (int) $r['goalsAgainst'], 'goal_difference' => (int) $r['goalDifference'], 'points' => (int) $r['points'],
                    ])->values()->all()];
            }, 600));
    }
}
