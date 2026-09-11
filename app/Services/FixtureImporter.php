<?php

namespace App\Services;

use App\Exceptions\ProviderUnavailable;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FixtureImporter
{
    public function __construct(private ProviderHttpClient $client) {}

    public function run(): int
    {
        if (! config('football.data_token')) {
            throw new ProviderUnavailable('Configure FOOTBALL_DATA_TOKEN before synchronizing.');
        }

        // A shared lock prevents overlapping imports from the scheduler and admin UI.
        return Cache::lock('football:fixture-sync', 120)->block(5, function () {
            $competition = config('football.competition', 'PL');
            if (! preg_match('/^[A-Z0-9]{1,10}$/', $competition)) {
                throw new ProviderUnavailable('Invalid competition code.');
            }
            $query = config('football.season') ? ['season' => (int) config('football.season')] : [];
            $data = $this->client->get('football-data', 'fixtures', 'https://api.football-data.org/v4/competitions/'.$competition.'/matches', ['X-Auth-Token' => config('football.data_token')], $query, function ($data) {
                $v = Validator::make(is_array($data) ? $data : [], [
                    'competition.id' => 'required|integer|min:1', 'competition.name' => 'required|string|max:100', 'matches' => 'present|array|max:1000',
                    'matches.*.id' => 'required|integer|min:1|distinct', 'matches.*.utcDate' => 'required|date',
                    'matches.*.homeTeam.id' => 'required|integer|min:1', 'matches.*.homeTeam.name' => 'required|string|max:100',
                    'matches.*.awayTeam.id' => 'required|integer|min:1', 'matches.*.awayTeam.name' => 'required|string|max:100',
                    'matches.*.status' => 'required|in:SCHEDULED,TIMED,IN_PLAY,PAUSED,EXTRA_TIME,PENALTY_SHOOTOUT,FINISHED,SUSPENDED,POSTPONED,CANCELLED,AWARDED',
                    'matches.*.matchday' => 'nullable|integer|between:1,1000', 'matches.*.season.startDate' => 'required|date_format:Y-m-d',
                    'matches.*.score.fullTime.home' => 'nullable|integer|between:0,100', 'matches.*.score.fullTime.away' => 'nullable|integer|between:0,100',
                ]);
                if ($v->fails()) {
                    throw new ProviderUnavailable('Malformed fixture response.');
                }
                foreach ($data['matches'] as $match) {
                    if ($match['status'] === 'FINISHED' && CarbonImmutable::parse($match['utcDate'])->isFuture()) {
                        throw new ProviderUnavailable('A future fixture cannot be finished.');
                    }
                    if ((string) $match['homeTeam']['id'] === (string) $match['awayTeam']['id']) {
                        throw new ProviderUnavailable('Invalid fixture teams.');
                    }
                    if ($match['status'] === 'FINISHED' && (! isset($match['score']['fullTime']['home']) || ! isset($match['score']['fullTime']['away']))) {
                        throw new ProviderUnavailable('Missing final score.');
                    }
                }

                return $data;
            }, 60);

            return DB::transaction(function () use ($data) {
                $source = 'football-data';
                $league = League::firstOrNew(['source' => $source, 'external_id' => (string) $data['competition']['id']]);
                $league->fill(['name' => $data['competition']['name'], 'country' => mb_substr($data['matches'][0]['area']['name'] ?? 'International', 0, 100)])->save();
                foreach ($data['matches'] as $match) {
                    $teams = [];
                    foreach (['homeTeam', 'awayTeam'] as $side) {
                        $team = Team::firstOrNew(['source' => $source, 'external_id' => $league->external_id.':'.$match[$side]['id']]);
                        $team->fill(['name' => $match[$side]['name'], 'league_id' => $league->id])->save();
                        $teams[] = $team;
                    }
                    $status = match ($match['status']) {
                        'FINISHED' => 'finished','SCHEDULED','TIMED' => 'scheduled','POSTPONED','SUSPENDED' => 'postponed','CANCELLED','AWARDED' => 'cancelled',default => 'live'
                    };
                    $fixture = Fixture::firstOrNew(['source' => $source, 'external_id' => (string) $match['id']]);
                    $fixture->fill(['league_id' => $league->id, 'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id, 'kickoff_at' => $match['utcDate'], 'season' => substr($match['season']['startDate'], 0, 4), 'matchday' => $match['matchday'] ?? null, 'status' => $status, 'is_finished' => $status === 'finished', 'home_goals' => $status === 'finished' ? $match['score']['fullTime']['home'] : null, 'away_goals' => $status === 'finished' ? $match['score']['fullTime']['away'] : null]);
                    if ($fixture->is_finished && (! $fixture->result_recorded_at || $fixture->isDirty(['home_goals', 'away_goals']))) {
                        $fixture->result_recorded_at = now();
                    }
                    if (! $fixture->is_finished) {
                        $fixture->result_recorded_at = null;
                    }
                    $fixture->save();
                }

                return count($data['matches']);
            }, 3);
        });
    }
}
