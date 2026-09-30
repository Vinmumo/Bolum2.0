<?php

namespace App\Services\Providers;

use App\Contracts\FootballDataProvider;
use App\Exceptions\ProviderUnavailable;
use App\Models\Fixture;
use App\Services\MatchHistory;
use App\Services\PoissonCalculator;
use Carbon\CarbonImmutable;

class ResultsFootballProvider implements FootballDataProvider
{
    public const VERSION = 'results-ratings-v2';

    /** Snapshots frozen by earlier versions stay valid for predictions that were already accepted. */
    private const READABLE_VERSIONS = ['results-rates-v1', self::VERSION];

    private const HALF_LIFE_DAYS = 90;

    /** Each team starts from this many league-average matches, which stabilizes small samples. */
    private const PRIOR_MATCHES = 5;

    public function __construct(private MatchHistory $history) {}

    public function snapshot(Fixture $fixture, CarbonImmutable $cutoff): array
    {
        if ($fixture->source !== 'football-data' || $cutoff >= $fixture->kickoff_at) {
            throw new ProviderUnavailable('The match-results model requires an upcoming imported fixture.');
        }
        $matches = $this->history->eligible($fixture, $cutoff)->orderByDesc('kickoff_at')->orderByDesc('id')->limit(1000)->get();
        $plays = fn ($team) => fn ($m) => $m->home_team_id === $team || $m->away_team_id === $team;
        $homeMatches = $matches->filter($plays($fixture->home_team_id));
        $awayMatches = $matches->filter($plays($fixture->away_team_id));
        if ($matches->count() < 20 || $homeMatches->count() < 3 || $awayMatches->count() < 3) {
            throw new ProviderUnavailable('Not enough recorded results: this model needs 20 league matches and 3 matches per team within the last year.');
        }
        $rows = $matches->map(fn ($m) => ['home' => $m->home_team_id, 'away' => $m->away_team_id, 'hg' => $m->home_goals, 'ag' => $m->away_goals,
            'w' => 0.5 ** (abs($cutoff->diffInSeconds($m->kickoff_at)) / 86400 / self::HALF_LIFE_DAYS)])->values()->all();
        $fit = $this->fit($rows);
        $rate = fn ($home, $away) => [
            max(0.15, min(5.0, $fit['home_rate'] * ($fit['attack'][$home] ?? 1) * ($fit['defence'][$away] ?? 1))),
            max(0.15, min(5.0, $fit['away_rate'] * ($fit['attack'][$away] ?? 1) * ($fit['defence'][$home] ?? 1))),
        ];
        [$home, $away] = $rate($fixture->home_team_id, $fixture->away_team_id);

        return ['model_version' => self::VERSION, 'source' => 'football-data', 'cutoff_at' => $cutoff->toIso8601String(),
            'home' => $home, 'away' => $away, 'rho' => $this->fitRho($rows, $rate),
            'league_matches' => $matches->count(), 'home_matches' => $homeMatches->count(), 'away_matches' => $awayMatches->count(),
            'limited_sample' => $homeMatches->count() < 5 || $awayMatches->count() < 5,
            'league_home_rate' => $fit['home_rate'], 'league_away_rate' => $fit['away_rate'],
            'home_attack' => $fit['attack'][$fixture->home_team_id], 'home_defence' => $fit['defence'][$fixture->home_team_id],
            'away_attack' => $fit['attack'][$fixture->away_team_id], 'away_defence' => $fit['defence'][$fixture->away_team_id],
            'iterations' => $fit['iterations'],
            'parameters' => ['lookback_days' => 365, 'half_life_days' => self::HALF_LIFE_DAYS, 'prior_matches' => self::PRIOR_MATCHES, 'rho_prior' => ['mean' => PoissonCalculator::DEFAULT_RHO, 'sd' => self::RHO_PRIOR_SD], 'minimum_expected_goals' => 0.15, 'maximum_expected_goals' => 5],
            'latest_match_at' => $matches->first()->kickoff_at->toIso8601String(),
            'oldest_match_at' => $matches->last()->kickoff_at->toIso8601String(), 'fixture_ids' => $matches->pluck('id')->all()];
    }

    /**
     * Weighted Poisson ratings: home goals ~ home_rate * attack[home] * defence[away], away goals ~ away_rate * attack[away] * defence[home].
     * Alternating closed-form updates converge to the maximum-likelihood fit, so a team's rating accounts for the strength of its opponents.
     */
    private function fit(array $rows): array
    {
        $total = array_sum(array_column($rows, 'w'));
        $homeRate = max(0.15, array_sum(array_map(fn ($r) => $r['hg'] * $r['w'], $rows)) / $total);
        $awayRate = max(0.15, array_sum(array_map(fn ($r) => $r['ag'] * $r['w'], $rows)) / $total);
        $teams = array_unique([...array_column($rows, 'home'), ...array_column($rows, 'away')]);
        $attack = $defence = array_fill_keys($teams, 1.0);
        $prior = self::PRIOR_MATCHES * ($homeRate + $awayRate) / 2;
        for ($iteration = 1; $iteration <= 200; $iteration++) {
            $scored = $expectedScored = $conceded = $expectedConceded = array_fill_keys($teams, $prior);
            foreach ($rows as $r) {
                $scored[$r['home']] += $r['w'] * $r['hg'];
                $scored[$r['away']] += $r['w'] * $r['ag'];
                $expectedScored[$r['home']] += $r['w'] * $homeRate * $defence[$r['away']];
                $expectedScored[$r['away']] += $r['w'] * $awayRate * $defence[$r['home']];
            }
            $nextAttack = array_map(fn ($team) => $scored[$team] / $expectedScored[$team], array_combine($teams, $teams));
            foreach ($rows as $r) {
                $conceded[$r['home']] += $r['w'] * $r['ag'];
                $conceded[$r['away']] += $r['w'] * $r['hg'];
                $expectedConceded[$r['home']] += $r['w'] * $awayRate * $nextAttack[$r['away']];
                $expectedConceded[$r['away']] += $r['w'] * $homeRate * $nextAttack[$r['home']];
            }
            $nextDefence = array_map(fn ($team) => $conceded[$team] / $expectedConceded[$team], array_combine($teams, $teams));
            // Only products attack * defence are identified; keep the geometric mean attack at 1.
            $scale = exp(array_sum(array_map('log', $nextAttack)) / count($nextAttack));
            $nextAttack = array_map(fn ($a) => $a / $scale, $nextAttack);
            $nextDefence = array_map(fn ($d) => $d * $scale, $nextDefence);
            $change = max(array_map(fn ($team) => max(abs($nextAttack[$team] - $attack[$team]), abs($nextDefence[$team] - $defence[$team])), $teams));
            [$attack, $defence] = [$nextAttack, $nextDefence];
            if ($change < 1e-7) {
                break;
            }
        }

        return ['home_rate' => $homeRate, 'away_rate' => $awayRate, 'attack' => $attack, 'defence' => $defence, 'iterations' => min($iteration, 200)];
    }

    /** Prior on rho: centred on the literature value, so small early-season samples cannot swing it to the grid edge. */
    private const RHO_PRIOR_SD = 0.05;

    /** Maximum a posteriori Dixon-Coles rho on a grid: the league's low-score evidence, shrunk toward the default. */
    private function fitRho(array $rows, callable $rate): float
    {
        $best = PoissonCalculator::DEFAULT_RHO;
        $bestLikelihood = null;
        for ($step = -40; $step <= 20; $step++) {
            $rho = (float) ($step / 200);
            $likelihood = -(($rho - PoissonCalculator::DEFAULT_RHO) ** 2) / (2 * self::RHO_PRIOR_SD ** 2);
            foreach ($rows as $r) {
                if ($r['hg'] > 1 || $r['ag'] > 1) {
                    continue;
                }
                [$lambda, $mu] = $rate($r['home'], $r['away']);
                $tau = PoissonCalculator::tau($r['hg'], $r['ag'], $lambda, $mu, $rho);
                $likelihood += $tau > 0 ? $r['w'] * log($tau) : -INF;
            }
            if ($bestLikelihood === null || $likelihood > $bestLikelihood) {
                [$best, $bestLikelihood] = [$rho, $likelihood];
            }
        }

        return $best;
    }

    public function expectedGoals(array $fixture): array
    {
        $snapshot = $fixture['results_inputs'] ?? null;
        if (! is_array($snapshot) || ! in_array($snapshot['model_version'] ?? null, self::READABLE_VERSIONS, true)
            || ! is_numeric($snapshot['home'] ?? null) || ! is_numeric($snapshot['away'] ?? null)) {
            throw new ProviderUnavailable('Frozen match-results inputs are unavailable.');
        }

        // The worker uses inputs frozen when the request was accepted, never today's DB state.
        return ['home' => (float) $snapshot['home'], 'away' => (float) $snapshot['away']];
    }
}
