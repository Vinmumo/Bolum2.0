<?php

namespace App\Actions\Catalog;

use App\Data\CreateLeagueData;
use App\Data\CreateTeamData;
use App\Data\ProviderData;
use App\Models\League;
use App\Models\Provider;
use App\Models\Team;

class CreateCatalogEntryAction
{
    public function execute(CreateLeagueData|CreateTeamData|ProviderData $data): League|Team|Provider
    {
        return match (true) {
            $data instanceof CreateLeagueData => League::create($data->attributes()),
            $data instanceof CreateTeamData => Team::create($data->attributes()),
            $data instanceof ProviderData => Provider::create($data->attributes()),
        };
    }
}
