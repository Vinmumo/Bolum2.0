<?php

namespace App\Services;

class PoissonCalculator
{
    /** Low-score dependence used when no league-fitted value exists; Dixon & Coles report about -0.13 for English football. */
    public const DEFAULT_RHO = -0.1;

    /**
     * Dixon-Coles adjusted Poisson: independent goal counts, with 0-0, 1-0, 0-1 and 1-1 reweighted by rho.
     * Negative rho raises 0-0 and 1-1 (draws), which plain Poisson under-predicts. Truncate at 30 goals and normalize the tail.
     */
    public function calculate(float $home, float $away, float $rho = self::DEFAULT_RHO): array
    {
        foreach ([$home, $away] as $value) {
            if (! is_finite($value) || $value <= 0 || $value > 10) {
                throw new \InvalidArgumentException('Expected goals must be in (0, 10].');
            }
        }
        if (! is_finite($rho) || $rho < -0.3 || $rho > 0.3) {
            throw new \InvalidArgumentException('Rho must be in [-0.3, 0.3].');
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
                $p = $hp * $ap * self::tau($i, $j, $home, $away, $rho);
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

        return ['totals' => $totals, 'scorelines' => $scorelines, 'probabilities' => $result, 'most_likely_score' => ['home' => $score[0], 'away' => $score[1]], 'expected_goals' => ['home' => $home, 'away' => $away], 'model' => 'dixon-coles-v1', 'rho' => $rho];
    }

    /** Dixon-Coles low-score adjustment; clamped at zero so extreme inputs cannot produce negative probabilities. */
    public static function tau(int $home, int $away, float $lambda, float $mu, float $rho): float
    {
        return max(0.0, match (true) {
            $home === 0 && $away === 0 => 1 - $lambda * $mu * $rho,
            $home === 0 && $away === 1 => 1 + $lambda * $rho,
            $home === 1 && $away === 0 => 1 + $mu * $rho,
            $home === 1 && $away === 1 => 1 - $rho,
            default => 1.0,
        });
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
