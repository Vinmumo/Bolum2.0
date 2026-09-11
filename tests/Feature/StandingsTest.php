<?php

namespace Tests\Feature;

use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StandingsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['competition' => ['id' => 2021], 'season' => ['startDate' => '2026-08-01'], 'standings' => [['type' => 'TOTAL', 'table' => [
            ['position' => 1, 'team' => ['id' => 57, 'name' => 'Arsenal FC', 'crest' => 'https://crests.football-data.org/57.png'], 'playedGames' => 3, 'won' => 2, 'draw' => 1, 'lost' => 0, 'points' => 7, 'goalsFor' => 6, 'goalsAgainst' => 2, 'goalDifference' => 4],
            ['position' => 2, 'team' => ['id' => 61, 'name' => 'Chelsea FC', 'crest' => 'https://untrusted.test/logo.svg'], 'playedGames' => 3, 'won' => 1, 'draw' => 0, 'lost' => 2, 'points' => -1, 'goalsFor' => 2, 'goalsAgainst' => 6, 'goalDifference' => -4],
        ]]]];
    }

    private function url(): string
    {
        $league = League::firstOrCreate(['name' => 'Premier League'], ['country' => 'England', 'source' => 'football-data', 'external_id' => '2021']);

        return '/api/v1/leagues/'.$league->id.'/standings';
    }

    public function test_official_table_is_cached_and_preserves_provider_points_with_safe_crests(): void
    {
        config(['football.data_token' => 'secret', 'football.season' => '2026']);
        Http::preventStrayRequests();
        Http::fake(['api.football-data.org/*' => Http::response($this->payload())]);
        $first = $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.team.crest_url', 'https://crests.football-data.org/57.png')
            ->assertJsonPath('data.rows.1.team.crest_url', null)->assertJsonPath('data.rows.1.points', -1);
        $this->travel(1)->minutes();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.fetched_at', $first->json('data.fetched_at'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Auth-Token', 'secret') && $request['season'] === 2026);
        $this->assertDatabaseHas('provider_calls', ['operation' => 'standings', 'cache_hit' => true]);
    }

    public function test_missing_configuration_and_upstream_failures_are_safe_and_recoverable(): void
    {
        Http::fake(['*' => Http::sequence()->push(['message' => 'sensitive upstream details'], 403)->push($this->payload())]);
        $this->getJson($this->url())->assertServiceUnavailable();
        Http::assertNothingSent();
        config(['football.data_token' => 'secret']);
        $this->getJson($this->url())->assertServiceUnavailable()->assertDontSee('sensitive')->assertDontSee('secret');
        $this->getJson($this->url())->assertOk();
        $local = League::create(['name' => 'Local', 'country' => 'England']);
        $this->getJson('/api/v1/leagues/'.$local->id.'/standings')->assertNotFound();
    }

    public function test_malformed_or_wrong_competition_tables_are_not_cached(): void
    {
        config(['football.data_token' => 'secret']);
        $wrong = $this->payload();
        $wrong['competition']['id'] = 999;
        $badRows = $this->payload();
        $badRows['standings'][0]['table'][0]['points'] = 'invalid';
        Http::fake(['*' => Http::sequence()->push($wrong)->push($badRows)->push($this->payload())]);
        $url = $this->url();
        $this->getJson($url)->assertServiceUnavailable();
        $this->getJson($url)->assertServiceUnavailable();
        $this->getJson($url)->assertOk();
        Http::assertSentCount(3);
    }

    public function test_tied_positions_are_preserved_but_duplicate_clubs_are_rejected(): void
    {
        config(['football.data_token' => 'secret']);
        $tied = $this->payload();
        $tied['standings'][0]['table'][1]['position'] = 1;
        $duplicate = $tied;
        $duplicate['standings'][0]['table'][1]['team']['id'] = 57;
        Http::fake(['*' => Http::sequence()->push($duplicate)->push($tied)]);
        $this->getJson($this->url())->assertServiceUnavailable();
        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.position', 1)->assertJsonPath('data.rows.1.position', 1);
    }
}
