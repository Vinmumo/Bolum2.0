<?php

namespace App\Actions\Catalog;

use App\Data\ProviderData;
use App\Models\Provider;

class UpdateProviderAction
{
    public function execute(Provider $provider, ProviderData $data): Provider
    {
        $provider->update($data->toArray());

        return $provider;
    }
}
