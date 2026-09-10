<?php

namespace App\Services;

use App\Contracts\FootballDataProvider;
use App\Exceptions\ProviderUnavailable;
use App\Services\Providers\HttpFootballProvider;

class PredictionCalculator
{
    public function __construct(private FootballDataProvider $sample, private HttpFootballProvider $http, private PoissonCalculator $poisson) {}

    public function calculate(array $fixture, array $providers): array
    {
        $home = 0;
        $away = 0;
        $weight = 0;
        foreach ($providers as $provider) {
            $driver = match ($provider['driver']) {
                'sample' => $this->sample,'http' => $this->http,default => throw new ProviderUnavailable('Unsupported provider.')
            };
            $goals = $driver->expectedGoals($fixture);
            $home += $goals['home'] * $provider['weight'];
            $away += $goals['away'] * $provider['weight'];
            $weight += $provider['weight'];
        }
        if ($weight <= 0) {
            throw new ProviderUnavailable('No active football providers.');
        }

        return $this->poisson->calculate($home / $weight, $away / $weight);
    }
}
