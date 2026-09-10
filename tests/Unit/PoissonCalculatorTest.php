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
}
