<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\Provider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_filters_support_both_url_styles_and_keep_filters_in_pagination(): void
    {
        $this->seed();
        Fixture::query()->update(['matchday' => 4, 'season' => 2026]);
        $legacy = $this->getJson('/api/v1/fixtures?q=North&matchday=4&season=2026&per_page=1&sort=-kickoff_at')->assertOk();
        $nested = $this->getJson('/api/v1/fixtures?filter[q]=North&filter[matchday]=4&filter[season]=2026&per_page=1&sort=-kickoff_at')->assertOk();
        $this->assertSame(2, $nested->json('meta.total'));
        $this->assertSame($legacy->json('data'), $nested->json('data'));
        $query = [];
        parse_str(parse_url($nested->json('links.next'), PHP_URL_QUERY), $query);
        $this->assertSame('North', $query['filter']['q']);
        $this->assertSame('-kickoff_at', $query['sort']);
        $this->assertSame('2', $query['page']);
        $this->getJson('/api/v1/fixtures?q=Missing&filter[q]=North')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_query_allowlists_reject_unknown_fields_and_invalid_values(): void
    {
        foreach (['filter[password]=secret', 'sort=password', 'include=predictions'] as $query) {
            $this->getJson('/api/v1/fixtures?'.$query)->assertStatus(400);
        }
        foreach (['filter[matchday]=bad', 'filter[q][]=North', 'filter=bad', 'sort[]=id', 'include[]=league', 'per_page=1000'] as $query) {
            $this->getJson('/api/v1/fixtures?'.$query)->assertUnprocessable();
        }
    }

    public function test_search_treats_commas_as_text_and_status_and_upcoming_filters_remain_distinct(): void
    {
        $this->seed();
        $team = Team::where('name', 'North London')->firstOrFail();
        $team->update(['name' => 'North, London']);
        $fixture = Fixture::firstOrFail();
        $fixture->update(['is_finished' => true]);
        $this->getJson('/api/v1/fixtures?filter[q]=North%2C%20London')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/fixtures?filter[status]=finished')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/fixtures?filter[upcoming]=1')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/fixtures?filter[upcoming]=0')->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_team_filters_sorting_and_optional_league_include(): void
    {
        $this->seed();
        $team = Team::where('name', 'North London')->firstOrFail();
        $this->getJson('/api/v1/teams?filter[name]=London&filter[league_id]='.$team->league_id.'&sort=-name&include=league')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'West London')
            ->assertJsonPath('data.0.league.id', $team->league_id);
        $this->getJson('/api/v1/teams')->assertOk()->assertJsonMissingPath('data.0.league');
        $this->getJson('/api/v1/leagues?filter[country]=England&sort=name')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_provider_query_features_keep_admin_authorization_and_filter_false(): void
    {
        $this->seed();
        Provider::create(['name' => 'Inactive', 'driver' => 'sample', 'weight' => 2, 'is_active' => false]);
        $url = '/api/v1/providers?filter[is_active]=0&filter[driver]=sample&sort=-weight';
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->firstOrFail());
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs(User::where('email', 'admin@bolum.test')->firstOrFail());
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Inactive');
    }
}
