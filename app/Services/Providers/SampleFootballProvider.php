<?php

namespace App\Services\Providers;

use App\Contracts\FootballDataProvider;

class SampleFootballProvider implements FootballDataProvider
{
    public function expectedGoals(array $fixture): array
    {
        // Deterministic synthetic data: the demo makes no accuracy claim.
        return ['home' => 1.2 + ($fixture['home_team_id'] % 5) * 0.12, 'away' => 0.9 + ($fixture['away_team_id'] % 5) * 0.1];
    }
}
