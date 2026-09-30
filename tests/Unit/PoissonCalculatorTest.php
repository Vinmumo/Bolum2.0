<?php

namespace Tests\Unit;

use App\Services\PoissonCalculator;
use PHPUnit\Framework\TestCase;

class PoissonCalculatorTest extends TestCase
{
    public function test_symmetric_teams_have_equal_win_probabilities(): void
    {
        $r = (new PoissonCalculator)->calculate(1.5, 1.5);
        $this->assertEqualsWithDelta($r['probabilities']['home_win'], $r['probabilities']['away_win'], 1e-12);
        $this->assertEqualsWithDelta(1, array_sum($r['probabilities']), 1e-12);
        $this->assertSame(['home' => 1, 'away' => 1], $r['most_likely_score']);
    }

    public function test_stronger_home_team_has_higher_win_probability(): void
    {
        $r = (new PoissonCalculator)->calculate(2, 0.8);
        $this->assertGreaterThan($r['probabilities']['away_win'], $r['probabilities']['home_win']);
    }

    public function test_nonfinite_input_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PoissonCalculator)->calculate(INF, 1);
    }

    public function test_negative_rho_raises_draws_and_keeps_a_valid_distribution(): void
    {
        $independent = (new PoissonCalculator)->calculate(1.3, 1.1, 0.0);
        $adjusted = (new PoissonCalculator)->calculate(1.3, 1.1, -0.13);
        $this->assertGreaterThan($independent['probabilities']['draw'], $adjusted['probabilities']['draw']);
        $this->assertEqualsWithDelta(1, array_sum($adjusted['probabilities']), 1e-12);
        $this->assertSame('dixon-coles-v1', $adjusted['model']);
    }

    public function test_zero_rho_matches_independent_poisson(): void
    {
        $r = (new PoissonCalculator)->calculate(1.0, 1.0, 0.0);
        // P(0-0) = e^-1 * e^-1 for two independent unit-rate counts.
        $zeroZero = collect($r['scorelines'])->firstWhere(fn ($s) => $s['home'] === 0 && $s['away'] === 0);
        $this->assertEqualsWithDelta(exp(-2), $zeroZero['probability'], 1e-9);
    }

    public function test_adjustment_is_clamped_for_extreme_inputs(): void
    {
        $this->assertSame(0.0, PoissonCalculator::tau(0, 1, 10, 1, -0.2));
        $r = (new PoissonCalculator)->calculate(10, 1, -0.2);
        foreach ($r['scorelines'] as $line) {
            $this->assertGreaterThanOrEqual(0, $line['probability']);
        }
        $this->expectException(\InvalidArgumentException::class);
        (new PoissonCalculator)->calculate(1, 1, 0.5);
    }
}
