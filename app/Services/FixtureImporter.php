<?php

namespace App\Services;

use App\Exceptions\ProviderUnavailable;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FixtureImporter
{
    public function __construct(private ProviderHttpClient $client) {}

    /** Days either side of today covered by a recent-window sync: settles late results and picks up schedule changes. */
    public const RECENT_DAYS_BACK = 3;

    public const RECENT_DAYS_AHEAD = 14;

    /**
     * Import every configured competition. A failure in one competition does not stop the others; it is reported after all have run.
     *
     * @param  int|null  $season  Season start year; null uses FOOTBALL_SEASON or the provider's current season.
     * @param  bool  $recent  Only fetch matches around today, for frequent scheduled syncs.
     */
    public function run(?int $season = null, bool $recent = false): int
    {
        if (! config('football.data_token')) {
            throw new ProviderUnavailable('Configure FOOTBALL_DATA_TOKEN before synchronizing.');
        }
        $season ??= config('football.season') ? (int) config('football.season') : null;
        $query = match (true) {
            $season !== null => ['season' => $season],
            $recent => ['dateFrom' => now()->subDays(self::RECENT_DAYS_BACK)->toDateString(), 'dateTo' => now()->addDays(self::RECENT_DAYS_AHEAD)->toDateString()],
            default => [],
        };
        $imported = 0;
        $failed = [];
        foreach ($this->competitions() as $competition) {
            try {
                $imported += $this->importCompetition($competition, $query);
            } catch (ProviderUnavailable $error) {
                $failed[] = $competition;
                $firstError ??= $error;
            }
        }
        if ($failed) {
            throw count($failed) === 1 && count($this->competitions()) === 1 ? $firstError
                : new ProviderUnavailable('Synchronization failed for '.implode(', ', $failed).'; other competitions were imported.');
        }

        return $imported;
    }

    /** Import the season before each competition's current one, as reported by the provider. */
    public function backfillPreviousSeason(): int
    {
        if (! config('football.data_token')) {
            throw new ProviderUnavailable('Configure FOOTBALL_DATA_TOKEN before synchronizing.');
        }
        $imported = 0;
        foreach ($this->competitions() as $competition) {
            $current = $this->client->get('football-data', 'competition', 'https://api.football-data.org/v4/competitions/'.$competition, ['X-Auth-Token' => config('football.data_token')], [], function ($data) {
                if (Validator::make(is_array($data) ? $data : [], ['currentSeason.startDate' => 'required|date_format:Y-m-d'])->fails()) {
                    throw new ProviderUnavailable('Malformed competition response.');
                }

                return ['season' => (int) substr($data['currentSeason']['startDate'], 0, 4)];
            }, 3600, waitForBudget: true);
            $imported += $this->importCompetition($competition, ['season' => $current['season'] - 1]);
        }

        return $imported;
    }

    /** @return list<string> */
    public function competitions(): array
    {
        $codes = config('football.competitions') ?: [config('football.competition', 'PL')];
        foreach ($codes as $code) {
            if (! is_string($code) || ! preg_match('/^[A-Z0-9]{1,10}$/', $code)) {
                throw new ProviderUnavailable('Invalid competition code.');
            }
        }

        return array_values(array_unique($codes));
    }

    private function importCompetition(string $competition, array $query): int
    {
        // A per-competition lock prevents overlapping imports from the scheduler and admin UI.
        return Cache::lock('football:fixture-sync:'.$competition, 120)->block(5, function () use ($competition, $query) {
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
            }, 60, waitForBudget: true);

            return DB::transaction(function () use ($data) {
                $source = 'football-data';
                $league = League::firstOrNew(['source' => $source, 'external_id' => (string) $data['competition']['id']]);
                // A recent-window response can be empty; keep the stored country rather than guessing one.
                $country = $data['matches'][0]['area']['name'] ?? ($league->country ?: 'International');
                $league->fill(['name' => $data['competition']['name'], 'country' => mb_substr($country, 0, 100)])->save();
                // Preload existing rows and upsert only changed ones, so a sync is a handful of queries
                // and updated_at still means "last changed" for match-history cutoffs.
                $teams = collect();
                foreach ($data['matches'] as $match) {
                    foreach (['homeTeam', 'awayTeam'] as $side) {
                        $id = $league->external_id.':'.$match[$side]['id'];
                        // Incomplete optional artwork must not discard a valid fixture batch.
                        $crest = Team::normalizeCrestUrl($match[$side]['crest'] ?? null);
                        $teams[$id] = ['name' => $match[$side]['name'], 'crest_url' => $crest ?? $teams[$id]['crest_url'] ?? null];
                    }
                }
                $existing = Team::where('source', $source)->whereIn('external_id', $teams->keys())->get()->keyBy('external_id');
                $rows = [];
                foreach ($teams as $externalId => $values) {
                    $team = $existing[$externalId] ?? new Team(['source' => $source, 'external_id' => $externalId]);
                    $team->fill(['name' => $values['name'], 'league_id' => $league->id]);
                    if ($values['crest_url']) {
                        $team->crest_url = $values['crest_url'];
                    }
                    if (! $team->exists || $team->isDirty()) {
                        $rows[] = $this->row($team, ['source', 'external_id', 'name', 'league_id', 'crest_url']);
                    }
                }
                $this->upsert(Team::class, $rows, ['name', 'league_id', 'crest_url']);
                $teamIds = Team::where('source', $source)->whereIn('external_id', $teams->keys())->pluck('id', 'external_id');

                $existing = Fixture::where('source', $source)->whereIn('external_id', array_map(fn ($m) => (string) $m['id'], $data['matches']))->get()->keyBy('external_id');
                $columns = ['league_id', 'home_team_id', 'away_team_id', 'kickoff_at', 'season', 'matchday', 'status', 'is_finished', 'home_goals', 'away_goals', 'result_recorded_at'];
                $rows = [];
                foreach ($data['matches'] as $match) {
                    $status = match ($match['status']) {
                        'FINISHED' => 'finished','SCHEDULED','TIMED' => 'scheduled','POSTPONED','SUSPENDED' => 'postponed','CANCELLED','AWARDED' => 'cancelled',default => 'live'
                    };
                    $fixture = $existing[(string) $match['id']] ?? new Fixture(['source' => $source, 'external_id' => (string) $match['id']]);
                    $fixture->fill(['league_id' => $league->id, 'home_team_id' => $teamIds[$league->external_id.':'.$match['homeTeam']['id']], 'away_team_id' => $teamIds[$league->external_id.':'.$match['awayTeam']['id']], 'kickoff_at' => $match['utcDate'], 'season' => substr($match['season']['startDate'], 0, 4), 'matchday' => $match['matchday'] ?? null, 'status' => $status, 'is_finished' => $status === 'finished', 'home_goals' => $status === 'finished' ? $match['score']['fullTime']['home'] : null, 'away_goals' => $status === 'finished' ? $match['score']['fullTime']['away'] : null]);
                    if ($fixture->is_finished && (! $fixture->result_recorded_at || $fixture->isDirty(['home_goals', 'away_goals']))) {
                        $fixture->result_recorded_at = now();
                    }
                    if (! $fixture->is_finished) {
                        $fixture->result_recorded_at = null;
                    }
                    if (! $fixture->exists || $fixture->isDirty()) {
                        $rows[] = $this->row($fixture, ['source', 'external_id', ...$columns]);
                    }
                }
                $this->upsert(Fixture::class, $rows, $columns);
                Cache::forget(Fixture::FILTER_OPTIONS_CACHE_KEY);

                return count($data['matches']);
            }, 3);
        });
    }

    /** Raw storage values (after casts and mutators) in a fixed column order for a bulk upsert. */
    private function row(Model $model, array $columns): array
    {
        $attributes = $model->getAttributes();

        return array_combine($columns, array_map(fn ($column) => $attributes[$column] ?? null, $columns));
    }

    private function upsert(string $model, array $rows, array $update): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $model::upsert($chunk, ['source', 'external_id'], $update);
        }
    }
}
