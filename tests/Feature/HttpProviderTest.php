<?php

namespace Tests\Feature;

use App\Exceptions\ProviderUnavailable;
use App\Services\Providers\HttpFootballProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpProviderTest extends TestCase
{
    public function test_gateway_is_faked_validated_and_cached(): void
    {
        config(['football.gateway_url' => 'https://football.example.test/expected-goals']);
        Http::preventStrayRequests();
        Http::fake(['football.example.test/*' => Http::response(['home' => 1.6, 'away' => 1.1])]);
        $provider = app(HttpFootballProvider::class);
        $this->assertSame(['home' => 1.6, 'away' => 1.1], $provider->expectedGoals(['id' => 1]));
        $provider->expectedGoals(['id' => 1]);
        Http::assertSentCount(1);
    }

    public function test_rate_limit_is_retried_without_exposing_response_body(): void
    {
        config(['football.gateway_url' => 'https://football.example.test/expected-goals']);
        Http::fakeSequence()->push(['secret' => 'private'], 429)->push(['secret' => 'private'], 429);
        try {
            app(HttpFootballProvider::class)->expectedGoals(['id' => 2]);
            $this->fail('Expected provider failure.');
        } catch (ProviderUnavailable $e) {
            $this->assertSame('Football gateway unavailable.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        Http::assertSentCount(2);
    }

    public function test_malformed_goals_are_rejected(): void
    {
        config(['football.gateway_url' => 'https://football.example.test/expected-goals']);
        Http::fake(['*' => Http::response(['home' => -1, 'away' => 2])]);
        $this->expectException(ProviderUnavailable::class);
        app(HttpFootballProvider::class)->expectedGoals(['id' => 3]);
    }
}
