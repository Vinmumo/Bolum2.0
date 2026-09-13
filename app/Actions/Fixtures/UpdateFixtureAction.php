<?php

namespace App\Actions\Fixtures;

use App\Data\UpdateFixtureData;
use App\Models\Fixture;

class UpdateFixtureAction
{
    public function execute(Fixture $fixture, UpdateFixtureData $data): Fixture
    {
        $fixture->update($data->toArray());

        return $fixture;
    }
}
