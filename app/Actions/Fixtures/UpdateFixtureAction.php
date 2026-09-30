<?php

namespace App\Actions\Fixtures;

use App\Data\UpdateFixtureData;
use App\Models\Fixture;

class UpdateFixtureAction
{
    public function execute(Fixture $fixture, UpdateFixtureData $data): Fixture
    {
        abort_if($fixture->isImported(), 409, 'Imported fixtures are managed by the football-data sync.');
        $changes = $data->toArray();
        // Reopening a finished fixture discards its recorded result.
        if (($changes['is_finished'] ?? null) === false && $fixture->is_finished) {
            $changes += ['status' => 'scheduled', 'home_goals' => null, 'away_goals' => null, 'result_recorded_at' => null];
        }
        $fixture->update($changes);

        return $fixture;
    }
}
