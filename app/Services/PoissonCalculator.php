<?php

namespace App\Services;

class PoissonCalculator
{
    /** Truncate at 30 goals and normalize the negligible omitted tail. */
    public function calculate(float $home, float $away): array
    {
        foreach ([$home, $away] as $value) {
            if (! is_finite($value) || $value <= 0 || $value > 10) {
                throw new \InvalidArgumentException('Expected goals must be in (0, 10].');
            }
        }
        $h = $this->distribution($home);
        $a = $this->distribution($away);
        $result = ['home_win' => 0.0, 'draw' => 0.0, 'away_win' => 0.0];
        $totals = ['over_1_5' => 0.0, 'over_2_5' => 0.0, 'over_3_5' => 0.0, 'both_teams_score' => 0.0];
        $scorelines = [];
        $best = -1;
        $score = [0, 0];
        foreach ($h as $i => $hp) {
            foreach ($a as $j => $ap) {
                $p = $hp * $ap;
                foreach (['1.5' => 'over_1_5', '2.5' => 'over_2_5', '3.5' => 'over_3_5'] as $line => $key) {
                    if ($i + $j > $line) {
                        $totals[$key] += $p;
                    }
                }
                if ($i > 0 && $j > 0) {
                    $totals['both_teams_score'] += $p;
                }
                $scorelines[] = ['home' => $i, 'away' => $j, 'probability' => $p];
                $result[$i > $j ? 'home_win' : ($i === $j ? 'draw' : 'away_win')] += $p;
                if ($p > $best) {
                    $best = $p;
                    $score = [$i, $j];
                }
            }
        }
        $sum = array_sum($result);
        foreach ($result as &$p) {
            $p /= $sum;
        }

        foreach ($totals as &$value) {
            $value /= $sum;
        }
        unset($value);
        usort($scorelines, fn ($a, $b) => $b['probability'] <=> $a['probability']);
        $scorelines = array_slice($scorelines, 0, 5);
        foreach ($scorelines as &$line) {
            $line['probability'] /= $sum;
        }
        unset($line);

        return ['totals' => $totals, 'scorelines' => $scorelines, 'probabilities' => $result, 'most_likely_score' => ['home' => $score[0], 'away' => $score[1]], 'expected_goals' => ['home' => $home, 'away' => $away], 'model' => 'independent-poisson-v1'];
    }

    private function distribution(float $lambda): array
    {
        $p = [exp(-$lambda)];
        for ($k = 1; $k <= 30; $k++) {
            $p[$k] = $p[$k - 1] * $lambda / $k;
        }

        return $p;
    }
}
