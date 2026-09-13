<?php

namespace App\Services;

use App\Models\Company;
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
        $buckets = [];
        for ($i = 0; $i < 5; $i++) {
            $buckets[] = ['label' => ($i * 20).'–'.(($i + 1) * 20).'%', 'count' => 0, 'confidence' => 0.0, 'accuracy' => 0.0];
        }
        // One frozen, pre-kickoff prediction per fixture. Newer requests supersede older ones.
        // Filter quality in PHP for consistent JSON behavior across database engines.
        $seen = [];
        $company->predictions()->where('status', 'completed')->whereNotNull('completed_at')->whereHas('fixture', fn ($q) => $q->where('is_finished', true)->whereNotNull('home_goals')->whereNotNull('away_goals'))
            ->with('fixture')->orderByDesc('id')->chunkByIdDesc(200, function ($predictions) use (&$count, &$correct, &$brier, &$logLoss, &$recent, &$buckets, &$seen, $quality) {
                foreach ($predictions as $p) {
                    $f = $p->fixture;
                    $probs = $this->eligibility->probabilities($p, $f, $quality);
                    if ($probs === null || isset($seen[$f->id])) {
                        continue;
                    }
                    $seen[$f->id] = true;
                    $actual = $f->home_goals > $f->away_goals ? 'home_win' : ($f->home_goals === $f->away_goals ? 'draw' : 'away_win');
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

        return ['quality' => $quality, 'count' => $count, 'accuracy' => $count ? $correct / $count : null, 'brier_score' => $count ? $brier / $count : null, 'log_loss' => $count ? $logLoss / $count : null, 'calibration' => $buckets, 'recent' => $recent, 'method' => 'Latest completed pre-kickoff prediction per fixture within the selected data category.'];
    }
}
