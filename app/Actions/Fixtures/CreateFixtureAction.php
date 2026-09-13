<?php

namespace App\Actions\Fixtures;

use App\Data\CreateFixtureData;
use App\Models\Fixture;

class CreateFixtureAction
{
    public function execute(CreateFixtureData $data): Fixture
    {
        return Fixture::create($data->attributes());
    }
}
