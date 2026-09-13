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
            $data instanceof CreateLeagueData => League::create($data->toArray()),
            $data instanceof CreateTeamData => Team::create($data->toArray()),
            $data instanceof ProviderData => Provider::create($data->toArray()),
        };
    }
}
