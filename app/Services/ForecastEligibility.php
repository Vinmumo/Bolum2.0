<?php

namespace App\Services;

use App\Models\Fixture;
use App\Models\Prediction;
use Carbon\CarbonImmutable;

class ForecastEligibility
{
    public function probabilities(Prediction $prediction, Fixture $fixture, string $quality): ?array
    {
        $snapshot = $prediction->fixture_snapshot;
        if (! $prediction->completed_at || ! isset($snapshot['kickoff_at'], $snapshot['home_team_id'], $snapshot['away_team_id'])) {
            return null;
        }
        $cutoff = CarbonImmutable::parse($snapshot['kickoff_at']);
        if ($prediction->created_at >= $cutoff || $prediction->completed_at >= $cutoff
            || $prediction->created_at >= $fixture->kickoff_at || $prediction->completed_at >= $fixture->kickoff_at
            || $snapshot['home_team_id'] != $fixture->home_team_id || $snapshot['away_team_id'] != $fixture->away_team_id
            || ($prediction->result['data_quality'] ?? null) !== $quality) {
            return null;
        }
        $probabilities = $prediction->result['probabilities'] ?? [];
        if (count($probabilities) !== 3 || ! isset($probabilities['home_win'], $probabilities['draw'], $probabilities['away_win'])) {
            return null;
        }
        foreach ($probabilities as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
                return null;
            }
        }

        return abs(array_sum($probabilities) - 1) < 0.00001 ? $probabilities : null;
    }
}
