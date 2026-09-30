<?php

namespace App\Services;

use App\Exceptions\ProviderUnavailable;
use App\Models\Fixture;
use App\Models\League;
use App\Models\MarketOdds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Saves bookmaker consensus probabilities for upcoming imported fixtures from The Odds API.
 * Odds are a benchmark for evaluating Bolum's forecasts; they are never used as model inputs.
 */
class OddsImporter
{
    public const SOURCE = 'the-odds-api';

    /** football-data.org competition codes mapped to The Odds API sport keys. */
    public const SPORTS = [
        'PL' => 'soccer_epl', 'ELC' => 'soccer_efl_champ', 'PD' => 'soccer_spain_la_liga', 'SA' => 'soccer_italy_serie_a',
        'BL1' => 'soccer_germany_bundesliga', 'FL1' => 'soccer_france_ligue_one', 'DED' => 'soccer_netherlands_eredivisie',
        'PPL' => 'soccer_portugal_primeira_liga', 'CL' => 'soccer_uefa_champs_league', 'BSA' => 'soccer_brazil_campeonato',
        'EC' => 'soccer_uefa_european_championship', 'WC' => 'soccer_fifa_world_cup',
    ];

    /** Stop before the monthly allowance runs out, leaving room for manual checks. */
    public const MINIMUM_CREDITS = 25;

    public const CREDITS_CACHE_KEY = 'odds:credits-remaining';

    /** Event and fixture kickoffs must agree within this window, in addition to both team names. */
    private const KICKOFF_TOLERANCE_MINUTES = 120;

    /** Words that differ between providers without identifying a club. */
    private const GENERIC_WORDS = ['fc', 'afc', 'cf', 'sc', 'club', 'football', 'the'];

    public function __construct(private ProviderHttpClient $client, private FixtureImporter $fixtures) {}

    /** @return array{saved: int, unmatched: int, credits_remaining: int|null} */
    public function run(): array
    {
        $key = config('football.odds_api_key');
        $regions = config('football.odds_regions', 'uk');
        if (! $key) {
            throw new ProviderUnavailable('Configure ODDS_API_KEY before synchronizing odds.');
        }
        if (! is_string($regions) || ! preg_match('/^[a-z]{2}(,[a-z]{2}){0,3}$/', $regions)) {
            throw new ProviderUnavailable('ODDS_REGIONS must be comma-separated region codes, e.g. uk or uk,eu.');
        }
        $result = ['saved' => 0, 'unmatched' => 0, 'credits_remaining' => Cache::get(self::CREDITS_CACHE_KEY)];
        foreach ($this->fixtures->competitions() as $competition) {
            $league = League::where('source', 'football-data')->where('code', $competition)->first();
            if (! isset(self::SPORTS[$competition]) || ! $league) {
                // Unsupported competitions, or leagues not imported yet (run fixtures:sync first), cost no credits.
                continue;
            }
            $remaining = Cache::get(self::CREDITS_CACHE_KEY);
            if ($remaining !== null && $remaining < self::MINIMUM_CREDITS) {
                throw new ProviderUnavailable('Odds API credits are nearly used up ('.$remaining.' left); skipping until the monthly reset.');
            }
            $response = $this->client->get(self::SOURCE, 'odds', 'https://api.the-odds-api.com/v4/sports/'.self::SPORTS[$competition].'/odds',
                [], ['apiKey' => $key, 'regions' => $regions, 'markets' => 'h2h', 'oddsFormat' => 'decimal', 'dateFormat' => 'iso'],
                fn ($data, $http = null) => $this->validate($data, $http), 600);
            if ($response['remaining'] !== null) {
                Cache::forever(self::CREDITS_CACHE_KEY, $response['remaining']);
                $result['credits_remaining'] = $response['remaining'];
            }
            foreach ($response['events'] as $event) {
                $fixture = $this->match($league, $event);
                $consensus = $fixture ? $this->consensus($event) : null;
                if (! $fixture || ! $consensus) {
                    $result['unmatched']++;

                    continue;
                }
                MarketOdds::create(['fixture_id' => $fixture->id, 'source' => self::SOURCE, 'external_id' => $event['id'], 'observed_at' => now(), ...$consensus]);
                $result['saved']++;
            }
        }

        return $result;
    }

    private function validate(mixed $data, $http): array
    {
        $validator = Validator::make(['events' => $data], [
            'events' => 'present|array|max:500', 'events.*.id' => 'required|string|max:100', 'events.*.commence_time' => 'required|date',
            'events.*.home_team' => 'required|string|max:100', 'events.*.away_team' => 'required|string|max:100',
            'events.*.bookmakers' => 'present|array|max:100',
        ]);
        if ($validator->fails()) {
            throw new ProviderUnavailable('Malformed odds response.');
        }
        $remaining = $http?->header('x-requests-remaining');

        return ['events' => $data, 'remaining' => is_numeric($remaining) ? (int) $remaining : null];
    }

    /** A fixture matches only if both club names agree and kickoffs are close; ambiguous or unknown events are skipped. */
    private function match(League $league, array $event): ?Fixture
    {
        $kickoff = CarbonImmutable::parse($event['commence_time']);
        if ($kickoff->isPast()) {
            return null;
        }
        $candidates = Fixture::with(['homeTeam', 'awayTeam'])->where('league_id', $league->id)->where('source', 'football-data')
            ->where('status', 'scheduled')->where('is_finished', false)
            ->whereBetween('kickoff_at', [$kickoff->subMinutes(self::KICKOFF_TOLERANCE_MINUTES), $kickoff->addMinutes(self::KICKOFF_TOLERANCE_MINUTES)])
            ->get()->filter(fn ($f) => self::sameClub($f->homeTeam->name, $event['home_team']) && self::sameClub($f->awayTeam->name, $event['away_team']));

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    public static function sameClub(string $a, string $b): bool
    {
        $words = fn ($name) => array_values(array_diff(
            preg_split('/\s+/', trim(preg_replace('/[^a-z0-9 ]+/', ' ', str_replace('&', ' and ', Str::lower(Str::ascii($name)))))),
            self::GENERIC_WORDS, ['']));
        [$x, $y] = [$words($a), $words($b)];
        if (! $x || ! $y) {
            return false;
        }

        // "Arsenal FC" = "Arsenal"; "Brighton & Hove Albion FC" = "Brighton and Hove Albion"; "Manchester United" != "Manchester City".
        return ! array_diff($x, $y) || ! array_diff($y, $x);
    }

    /**
     * Average of each bookmaker's margin-free probabilities: 1/price per outcome, divided by that bookmaker's total.
     *
     * @return array{bookmakers: int, home_win: float, draw: float, away_win: float, average_margin: float, bookmaker_updated_at: ?string}|null
     */
    private function consensus(array $event): ?array
    {
        $rows = [];
        $updated = null;
        foreach ($event['bookmakers'] as $bookmaker) {
            $market = collect($bookmaker['markets'] ?? [])->firstWhere('key', 'h2h');
            $prices = [];
            foreach ($market['outcomes'] ?? [] as $outcome) {
                $side = match ($outcome['name'] ?? null) {
                    $event['home_team'] => 'home_win', $event['away_team'] => 'away_win', 'Draw' => 'draw', default => null,
                };
                if ($side && is_numeric($outcome['price'] ?? null) && $outcome['price'] > 1) {
                    $prices[$side] = 1 / $outcome['price'];
                }
            }
            if (count($prices) !== 3) {
                continue;
            }
            $total = array_sum($prices);
            $rows[] = ['home_win' => $prices['home_win'] / $total, 'draw' => $prices['draw'] / $total, 'away_win' => $prices['away_win'] / $total, 'margin' => $total - 1];
            $time = isset($bookmaker['last_update']) ? CarbonImmutable::parse($bookmaker['last_update']) : null;
            $updated = $time && (! $updated || $time > $updated) ? $time : $updated;
        }
        if (! $rows) {
            return null;
        }
        $mean = fn ($key) => array_sum(array_column($rows, $key)) / count($rows);

        return ['bookmakers' => count($rows), 'home_win' => $mean('home_win'), 'draw' => $mean('draw'), 'away_win' => $mean('away_win'),
            'average_margin' => $mean('margin'), 'bookmaker_updated_at' => $updated];
    }
}
