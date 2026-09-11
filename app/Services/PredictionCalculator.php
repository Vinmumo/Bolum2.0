<?php

namespace App\Services;

use App\Contracts\FootballDataProvider;
use App\Exceptions\ProviderUnavailable;
use App\Services\Providers\HttpFootballProvider;
use App\Services\Providers\ResultsFootballProvider;

class PredictionCalculator
{
    public function __construct(private FootballDataProvider $sample, private HttpFootballProvider $http, private PoissonCalculator $poisson, private ResultsFootballProvider $results) {}

    public function calculate(array $fixture, array $providers): array
    {
        $home = 0;
        $away = 0;
        $weight = 0;
        $sources = [];
        foreach ($providers as $provider) {
            $driver = match ($provider['driver']) {
                'sample' => $this->sample,'http' => $this->http,'results' => $this->results,default => throw new ProviderUnavailable('Unsupported provider.')
            };
            $goals = $driver->expectedGoals($fixture);
            $result = $this->poisson->calculate($goals['home'], $goals['away']);
            $sources[] = ['id' => $provider['id'] ?? null, 'name' => $provider['name'] ?? $provider['driver'], 'driver' => $provider['driver'], 'weight' => (float) $provider['weight'], 'expected_goals' => $goals, 'probabilities' => $result['probabilities']];
            if ($provider['driver'] === 'results') {
                $sources[array_key_last($sources)]['evidence'] = $fixture['results_inputs'];
            }
            $home += $goals['home'] * $provider['weight'];
            $away += $goals['away'] * $provider['weight'];
            $weight += $provider['weight'];
        }
        if ($weight <= 0) {
            throw new ProviderUnavailable('No active football providers.');
        }
        $sampleCount = count(array_filter($sources, fn ($s) => $s['driver'] === 'sample'));

        return [...$this->poisson->calculate($home / $weight, $away / $weight), 'sources' => $sources, 'data_quality' => $sampleCount === count($sources) ? 'sample' : ($sampleCount ? 'mixed' : 'external')];
    }
}
