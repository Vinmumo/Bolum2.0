<?php

namespace App\Services;

use App\Models\Company;
use App\Models\MarketOdds;
use App\Models\Prediction;

class PerformanceService
{
    public function __construct(private ForecastEligibility $eligibility) {}

    public function report(Company $company, string $quality = 'external'): array
    {
        $count = 0;
        $correct = 0;
        $brier = 0;
        $logLoss = 0;
        $recent = [];
        $outcomes = [];
        $evaluated = [];
        $buckets = [];
        for ($i = 0; $i < 5; $i++) {
            $buckets[] = ['label' => ($i * 20).'–'.(($i + 1) * 20).'%', 'count' => 0, 'confidence' => 0.0, 'accuracy' => 0.0];
        }
        // One frozen, pre-kickoff prediction per fixture. Newer requests supersede older ones.
        // Filter quality in PHP for consistent JSON behavior across database engines.
        $seen = [];
        $company->predictions()->where('status', 'completed')->whereNotNull('completed_at')->whereHas('fixture', fn ($q) => $q->where('is_finished', true)->whereNotNull('home_goals')->whereNotNull('away_goals'))
            ->with('fixture')->orderByDesc('id')->chunkByIdDesc(200, function ($predictions) use (&$count, &$correct, &$brier, &$logLoss, &$recent, &$buckets, &$seen, &$outcomes, &$evaluated, $quality) {
                foreach ($predictions as $p) {
                    $f = $p->fixture;
                    $probs = $this->eligibility->probabilities($p, $f, $quality);
                    if ($probs === null || isset($seen[$f->id])) {
                        continue;
                    }
                    $seen[$f->id] = true;
                    $actual = $f->home_goals > $f->away_goals ? 'home_win' : ($f->home_goals === $f->away_goals ? 'draw' : 'away_win');
                    $outcomes[] = $actual;
                    $evaluated[$f->id] = ['probabilities' => $probs, 'actual' => $actual, 'kickoff_at' => $f->kickoff_at];
                    $pick = array_keys($probs, max($probs), true)[0];
                    $hit = $pick === $actual;
                    $confidence = max($probs);
                    $count++;
                    $correct += (int) $hit;
                    foreach ($probs as $outcome => $prob) {
                        $brier += ($prob - ($outcome === $actual ? 1 : 0)) ** 2;
                    }
                    $logLoss -= log(max(1e-15, $probs[$actual]));
                    $index = min(4, (int) floor($confidence * 5));
                    $buckets[$index]['count']++;
                    $buckets[$index]['confidence'] += $confidence;
                    $buckets[$index]['accuracy'] += (int) $hit;
                    if (count($recent) < 20) {
                        $recent[] = ['prediction_id' => $p->id, 'fixture_id' => $f->id, 'fixture' => $p->fixture_snapshot, 'actual' => $actual, 'pick' => $pick, 'correct' => $hit, 'confidence' => $confidence, 'home_goals' => $f->home_goals, 'away_goals' => $f->away_goals];
                    }
                }
            });
        foreach ($buckets as &$bucket) {
            if ($bucket['count']) {
                $bucket['confidence'] /= $bucket['count'];
                $bucket['accuracy'] /= $bucket['count'];
            }
        }

        $baselines = $this->baselines($outcomes);

        return ['quality' => $quality, 'count' => $count, 'accuracy' => $count ? $correct / $count : null, 'brier_score' => $count ? $brier / $count : null, 'log_loss' => $count ? $logLoss / $count : null,
            'baselines' => $baselines, 'market' => $this->market($evaluated), 'brier_skill' => $count && $baselines['base_rate']['brier_score'] > 0 ? 1 - ($brier / $count) / $baselines['base_rate']['brier_score'] : null, 'calibration' => $buckets, 'recent' => $recent, 'method' => 'Latest completed pre-kickoff prediction per fixture within the selected data category.'];
    }

    /**
     * Naive forecasts scored on the same fixtures. The base rate for each fixture uses the other evaluated
     * outcomes (leave-one-out, add-one smoothed), so it never sees the result it is scored against.
     */
    private function baselines(array $outcomes): array
    {
        $n = count($outcomes);
        $score = function (callable $forecast) use ($outcomes, $n) {
            if (! $n) {
                return ['brier_score' => null, 'log_loss' => null];
            }
            [$brier, $logLoss] = [0.0, 0.0];
            foreach ($outcomes as $actual) {
                $probs = $forecast($actual);
                foreach ($probs as $outcome => $prob) {
                    $brier += ($prob - ($outcome === $actual ? 1 : 0)) ** 2;
                }
                $logLoss -= log($probs[$actual]);
            }

            return ['brier_score' => $brier / $n, 'log_loss' => $logLoss / $n];
        };
        $counts = array_merge(['home_win' => 0, 'draw' => 0, 'away_win' => 0], array_count_values($outcomes));

        return [
            'uniform' => $score(fn () => ['home_win' => 1 / 3, 'draw' => 1 / 3, 'away_win' => 1 / 3]),
            'base_rate' => $score(fn ($actual) => array_map(fn ($outcome) => ($counts[$outcome] - ($outcome === $actual ? 1 : 0) + 1) / ($n - 1 + 3),
                ['home_win' => 'home_win', 'draw' => 'draw', 'away_win' => 'away_win'])),
        ];
    }

    /**
     * Bolum versus the bookmaker consensus on the fixtures that have both: the latest odds observed before kickoff.
     * Odds taken close to kickoff know more than an earlier forecast, so this is a demanding benchmark.
     */
    private function market(array $evaluated): ?array
    {
        $odds = [];
        foreach (array_chunk(array_keys($evaluated), 500) as $ids) {
            MarketOdds::whereIn('fixture_id', $ids)->orderByDesc('observed_at')->orderByDesc('id')->get()
                ->each(function (MarketOdds $snapshot) use (&$odds, $evaluated) {
                    if (! isset($odds[$snapshot->fixture_id]) && $snapshot->observed_at < $evaluated[$snapshot->fixture_id]['kickoff_at']) {
                        $odds[$snapshot->fixture_id] = $snapshot->probabilities();
                    }
                });
        }
        if (! $odds) {
            return null;
        }
        $score = function (callable $forecast) use ($odds, $evaluated) {
            [$brier, $logLoss] = [0.0, 0.0];
            foreach (array_keys($odds) as $id) {
                $probs = $forecast($id);
                $actual = $evaluated[$id]['actual'];
                foreach ($probs as $outcome => $prob) {
                    $brier += ($prob - ($outcome === $actual ? 1 : 0)) ** 2;
                }
                $logLoss -= log(max(1e-15, $probs[$actual]));
            }

            return ['brier_score' => $brier / count($odds), 'log_loss' => $logLoss / count($odds)];
        };

        return ['count' => count($odds), 'bolum' => $score(fn ($id) => $evaluated[$id]['probabilities']), 'bookmakers' => $score(fn ($id) => $odds[$id])];
    }
}
