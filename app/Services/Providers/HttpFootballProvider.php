<?php

namespace App\Services\Providers;

use App\Contracts\FootballDataProvider;
use App\Exceptions\ProviderUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HttpFootballProvider implements FootballDataProvider
{
    public function expectedGoals(array $fixture): array
    {
        // This is a configurable gateway contract, not a vendor-specific API mapping.
        $url = config('football.gateway_url');
        if (! $url) {
            throw new ProviderUnavailable('Football gateway is not configured.');
        }

        return Cache::remember('football:gateway:'.hash('sha256', $url.json_encode($fixture)), 300, function () use ($url, $fixture) {
            try {
                $response = Http::acceptJson()->withToken(config('football.gateway_token', ''))->connectTimeout(3)->timeout(8)->retry(2, 200)->get($url, ['fixture_id' => $fixture['id']])->throw();
                $data = $response->json();
                foreach (['home', 'away'] as $side) {
                    if (! isset($data[$side]) || ! is_numeric($data[$side]) || ! is_finite((float) $data[$side]) || $data[$side] <= 0 || $data[$side] > 10) {
                        throw new ProviderUnavailable('Invalid football gateway response.');
                    }
                }

                return ['home' => (float) $data['home'], 'away' => (float) $data['away']];
            } catch (\Throwable $e) {
                // Do not attach an HTTP exception that may contain a credential or response body.
                throw new ProviderUnavailable('Football gateway unavailable.');
            }
        });
    }
}
