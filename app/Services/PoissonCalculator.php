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
        $best = -1;
        $score = [0, 0];
        foreach ($h as $i => $hp) {
            foreach ($a as $j => $ap) {
                $p = $hp * $ap;
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

        return ['probabilities' => $result, 'most_likely_score' => ['home' => $score[0], 'away' => $score[1]], 'expected_goals' => ['home' => $home, 'away' => $away], 'model' => 'independent-poisson-v1'];
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
