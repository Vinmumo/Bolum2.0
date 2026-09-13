<?php

namespace App\Services;

use App\Exceptions\ProviderUnavailable;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ClubProfileService
{
    public function __construct(private ProviderHttpClient $client) {}

    public function report(Team $team): array
    {
        $key = (string) config('football.sportsdb_key');
        if (! preg_match('/\A[0-9]+\z/', $key)) {
            throw new ProviderUnavailable('Club profiles are not configured.');
        }
        $team->loadMissing('league');
        $name = preg_replace('/\s+(?:FC|AFC)\z/i', '', trim($team->name));

        return Cache::lock('football:club-profile:'.$team->id, 30)->block(5, fn () => $this->client->get(
            'thesportsdb', 'club_profile', 'https://www.thesportsdb.com/api/v1/json/'.$key.'/searchteams.php', [], ['t' => $name],
            function ($data) use ($name, $team) {
                if (! is_array($data) || ! array_key_exists('teams', $data)
                    || ($data['teams'] !== null && (! is_array($data['teams']) || count($data['teams']) > 100))) {
                    throw new ProviderUnavailable('Invalid club profile response.');
                }
                $matches = collect($data['teams'] ?? [])->filter(function ($row) use ($name, $team) {
                    if (! is_array($row) || ($row['strSport'] ?? null) !== 'Soccer'
                        || $this->identity($row['strCountry'] ?? '') !== $this->identity($team->league->country)) {
                        return false;
                    }
                    $aliases = is_string($row['strTeamAlternate'] ?? null) ? explode(',', $row['strTeamAlternate']) : [];

                    return collect([$row['strTeam'] ?? '', ...$aliases])->contains(fn ($alias) => $this->identity($alias) === $this->identity($name));
                });
                // Never guess when names are ambiguous or the sport/country does not match.
                if ($matches->count() !== 1) {
                    return ['profile' => null, 'fetched_at' => now()->toIso8601String()];
                }
                $row = $matches->first();
                $validation = Validator::make($row, [
                    'idTeam' => 'required|integer|min:1', 'strTeam' => 'required|string|max:200',
                    'strStadium' => 'nullable|string|max:200', 'strLocation' => 'nullable|string|max:200',
                    'intFormedYear' => 'nullable|integer|between:1800,'.now()->year,
                    'strDescriptionEN' => 'nullable|string|max:50000',
                ]);
                if ($validation->fails()) {
                    throw new ProviderUnavailable('Invalid club profile fields.');
                }
                $plain = fn ($value) => is_string($value) && trim($value) !== '' ? trim(strip_tags($value)) : null;

                return ['profile' => [
                    'external_id' => (string) $row['idTeam'], 'name' => $row['strTeam'],
                    'stadium' => $plain($row['strStadium'] ?? null), 'location' => $plain($row['strLocation'] ?? null),
                    'formed_year' => isset($row['intFormedYear']) && $row['intFormedYear'] !== '' ? (int) $row['intFormedYear'] : null,
                    'description' => Str::limit($plain($row['strDescriptionEN'] ?? null) ?? '', 600),
                ], 'fetched_at' => now()->toIso8601String()];
            }, 3600, $this->identity($team->league->country),
        ));
    }

    private function identity(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii(preg_replace('/\s+(?:FC|AFC)\z/i', '', trim($value)))));
    }
}
