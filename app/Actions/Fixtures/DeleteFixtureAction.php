<?php

namespace App\Actions\Fixtures;

use App\Models\Fixture;

class DeleteFixtureAction
{
    public function execute(Fixture $fixture): void
    {
        abort_if($fixture->isImported(), 409, 'Imported fixtures are managed by the football-data sync.');
        abort_if($fixture->predictions()->exists(), 409, 'Fixtures with prediction history cannot be deleted.');
        $fixture->delete();
    }
}
