<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClubProfileTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $source = 'football-data'): string
    {
        $league = League::firstOrCreate(['name' => 'Premier League'], ['country' => 'England']);
        $team = Team::firstOrCreate(['name' => 'Arsenal FC', 'league_id' => $league->id], ['source' => $source]);

        return '/api/v1/teams/'.$team->id.'/profile';
    }

    private function row(): array
    {
        return ['idTeam' => '133604', 'strTeam' => 'Arsenal', 'strSport' => 'Soccer', 'strCountry' => 'England',
            'strStadium' => 'Emirates Stadium', 'strLocation' => 'London', 'intFormedYear' => '1886', 'strDescriptionEN' => '<b>A football club.</b>'];
    }

    public function test_public_club_profile_is_normalized_cached_and_attributed(): void
    {
        config(['football.sportsdb_key' => '123']);
        Http::preventStrayRequests();
        Http::fake(['www.thesportsdb.com/*' => Http::response(['teams' => [$this->row()]])]);
        $url = $this->url();
        $first = $this->getJson($url)->assertOk()->assertJsonPath('data.stadium', 'Emirates Stadium')
            ->assertJsonPath('data.formed_year', 1886)->assertJsonPath('data.description', 'A football club.')
            ->assertJsonPath('data.source', 'TheSportsDB')->assertJsonPath('data.source_url', 'https://www.thesportsdb.com/team/133604');
        $this->travel(10)->minutes();
        $this->getJson($url)->assertOk()->assertJsonPath('data.fetched_at', $first->json('data.fetched_at'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['t'] === 'Arsenal');
        $this->assertDatabaseHas('provider_calls', ['source' => 'thesportsdb', 'cache_hit' => true]);
        $this->assertDatabaseCount('predictions', 0);
    }

    public function test_local_teams_and_disabled_configuration_do_not_call_upstream(): void
    {
        Http::fake();
        $url = $this->url('local');
        $this->getJson($url)->assertNotFound();
        Team::query()->update(['source' => 'football-data']);
        config(['football.sportsdb_key' => '']);
        $this->getJson($url)->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_same_name_in_another_country_does_not_reuse_a_normalized_profile(): void
    {
        config(['football.sportsdb_key' => '123']);
        Http::fake(['*' => Http::sequence()->push(['teams' => [$this->row()]])
            ->push(['teams' => [array_replace($this->row(), ['idTeam' => '999', 'strCountry' => 'Argentina'])]])]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.external_id', '133604');
        $league = League::create(['name' => 'Argentine League', 'country' => 'Argentina']);
        $team = Team::create(['name' => 'Arsenal FC', 'league_id' => $league->id, 'source' => 'football-data']);
        $this->getJson('/api/v1/teams/'.$team->id.'/profile')->assertOk()->assertJsonPath('data.external_id', '999');
        Http::assertSentCount(2);
    }

    public function test_wrong_club_sport_country_and_ambiguous_results_are_not_misidentified(): void
    {
        config(['football.sportsdb_key' => '123']);
        $url = $this->url();
        foreach ([['strTeam' => 'Chelsea'], ['strSport' => 'Basketball'], ['strCountry' => 'Argentina']] as $override) {
            cache()->flush();
            Http::fake(['*' => Http::response(['teams' => [array_replace($this->row(), $override)]])]);
            $this->getJson($url)->assertNotFound();
        }
        cache()->flush();
        Http::fake(['*' => Http::response(['teams' => [$this->row(), $this->row()]])]);
        $this->getJson($url)->assertNotFound();
    }

    public function test_empty_search_is_cached_but_invalid_fields_can_be_retried(): void
    {
        config(['football.sportsdb_key' => '123']);
        $url = $this->url();
        Http::fake(['*' => Http::sequence()->push(['teams' => null])->push(['teams' => [array_replace($this->row(), ['idTeam' => 'javascript:bad'])]])->push(['teams' => [$this->row()]])]);
        $this->getJson($url)->assertNotFound();
        $this->getJson($url)->assertNotFound();
        Http::assertSentCount(1);
        cache()->flush();
        $this->getJson($url)->assertServiceUnavailable();
        $this->getJson($url)->assertOk();
        Http::assertSentCount(3);
    }

    public function test_rate_limited_upstream_retries_without_exposing_its_key_or_body(): void
    {
        config(['football.sportsdb_key' => '999999']);
        Http::fake(['*' => Http::sequence()->push(['error' => 'private upstream details'], 429)->push([], 503)->push(['teams' => [$this->row()]])]);
        $url = $this->url();
        $this->getJson($url)->assertServiceUnavailable()->assertDontSee('999999')->assertDontSee('private upstream');
        Http::assertSentCount(2);
        $this->getJson($url)->assertOk();
    }
}
