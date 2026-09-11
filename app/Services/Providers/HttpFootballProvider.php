<?php

namespace App\Services\Providers;

use App\Contracts\FootballDataProvider;
use App\Exceptions\ProviderUnavailable;
use App\Services\ProviderHttpClient;

class HttpFootballProvider implements FootballDataProvider
{
    public function __construct(private ProviderHttpClient $client) {}

    public function expectedGoals(array $fixture): array
    {
        $url = config('football.gateway_url');
        if (! $url) {
            throw new ProviderUnavailable('Football gateway is not configured.');
        }

        return $this->client->get('gateway', 'expected_goals', $url, ['Authorization' => 'Bearer '.config('football.gateway_token', '')], ['fixture_id' => $fixture['id']], function ($data) {
            foreach (['home', 'away'] as $side) {
                if (! isset($data[$side]) || ! is_numeric($data[$side]) || ! is_finite((float) $data[$side]) || $data[$side] <= 0 || $data[$side] > 10) {
                    throw new ProviderUnavailable('Invalid expected goals.');
                }
            }

            return ['home' => (float) $data['home'], 'away' => (float) $data['away']];
        });
    }
}
