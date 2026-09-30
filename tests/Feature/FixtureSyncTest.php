<?php

namespace Tests\Feature;

use App\Exceptions\ProviderUnavailable;
use App\Jobs\SyncFixtures;
use App\Models\Fixture;
use App\Models\FixtureSync;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use App\Services\FixtureImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FixtureSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_crest_metadata_is_imported_exposed_and_preserved_when_optional_data_is_missing(): void
    {
        config(['football.data_token' => 'secret']);
        $data = $this->payload();
        $data['matches'][0]['homeTeam']['crest'] = 'https://crests.football-data.org/1.svg';
        $data['matches'][0]['awayTeam']['crest'] = 'https://crests.football-data.org/2.png';
        Http::fake(['*' => Http::response($data)]);
        app(FixtureImporter::class)->run();
        $this->getJson('/api/v1/fixtures?upcoming=1')->assertOk()->assertJsonPath('data.0.home_team.crest_url', 'https://crests.football-data.org/1.svg');
        $this->getJson('/api/v1/teams')->assertOk()->assertJsonPath('data.0.crest_url', 'https://crests.football-data.org/1.svg');
        Cache::flush();
        Http::fake(['*' => Http::response($this->payload())]);
        app(FixtureImporter::class)->run();
        $this->assertDatabaseHas('teams', ['external_id' => '2021:1', 'crest_url' => 'https://crests.football-data.org/1.svg']);
    }

    public function test_untrusted_crest_urls_are_ignored_without_rejecting_the_fixture_batch(): void
    {
        config(['football.data_token' => 'secret']);
        $data = $this->payload();
        $data['matches'][0]['homeTeam']['crest'] = 'https://untrusted.example/logo.svg';
        $data['matches'][0]['awayTeam']['crest'] = ['malformed'];
        Http::fake(['*' => Http::response($data)]);
        $this->assertSame(2, app(FixtureImporter::class)->run());
        $this->assertSame(0, Team::whereNotNull('crest_url')->count());
        Team::first()->update(['crest_url' => 'javascript:alert(1)']);
        $this->getJson('/api/v1/teams')->assertOk()->assertJsonPath('data.0.crest_url', null);
    }

    private function payload(): array
    {
        return ['competition' => ['id' => 2021, 'name' => 'Premier League'], 'matches' => [
            ['id' => 100, 'area' => ['name' => 'England'], 'utcDate' => now()->addDays(2)->toIso8601String(), 'season' => ['startDate' => '2026-08-01'], 'matchday' => 3, 'status' => 'TIMED', 'homeTeam' => ['id' => 1, 'name' => 'London'], 'awayTeam' => ['id' => 2, 'name' => 'Manchester'], 'score' => ['fullTime' => ['home' => null, 'away' => null]]],
            ['id' => 101, 'area' => ['name' => 'England'], 'utcDate' => now()->subDays(2)->toIso8601String(), 'season' => ['startDate' => '2026-08-01'], 'matchday' => 2, 'status' => 'FINISHED', 'homeTeam' => ['id' => 2, 'name' => 'Manchester'], 'awayTeam' => ['id' => 1, 'name' => 'London'], 'score' => ['fullTime' => ['home' => 2, 'away' => 1]]],
        ]];
    }

    public function test_sync_imports_upstream_ids_dates_and_scores_idempotently(): void
    {
        config(['football.data_token' => 'secret']);
        Http::preventStrayRequests();
        Http::fake(['api.football-data.org/*' => Http::response($this->payload())]);
        $importer = app(FixtureImporter::class);
        $this->assertSame(2, $importer->run());
        $this->assertSame(2, $importer->run());
        $this->assertDatabaseCount('fixtures', 2);
        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseCount('leagues', 1);
        $this->assertDatabaseHas('fixtures', ['external_id' => '101', 'status' => 'finished', 'home_goals' => 2, 'away_goals' => 1, 'season' => '2026', 'matchday' => 2]);
        $league = $this->getJson('/api/v1/leagues')->assertOk()->assertJsonPath('data.0.source', 'football-data')->json('data.0.id');
        $this->getJson('/api/v1/fixtures?upcoming=1&league_id='.$league)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.home_team.name', 'London')->assertJsonPath('data.0.source', 'football-data');
        Http::assertSent(fn ($r) => $r->hasHeader('X-Auth-Token', 'secret') && str_contains($r->url(), '/competitions/PL/matches'));
        Http::assertSentCount(1);
    }

    public function test_sync_succeeds_when_a_local_league_has_the_same_name(): void
    {
        config(['football.data_token' => 'secret']);
        League::create(['name' => 'Premier League', 'country' => 'England']);
        Http::fake(['*' => Http::response($this->payload())]);
        $this->assertSame(2, app(FixtureImporter::class)->run());
        $this->assertDatabaseHas('leagues', ['name' => 'Premier League', 'source' => 'football-data']);
        $this->assertSame(2, League::where('name', 'Premier League')->count());
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/api/v1/leagues', ['name' => 'Premier League', 'country' => 'England'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_sync_uses_a_bounded_number_of_queries_and_skips_unchanged_rows(): void
    {
        config(['football.data_token' => 'secret']);
        $data = $this->payload();
        for ($i = 0; $i < 100; $i++) {
            $data['matches'][] = ['id' => 1000 + $i, 'area' => ['name' => 'England'], 'utcDate' => now()->addDays(3)->toIso8601String(), 'season' => ['startDate' => '2026-08-01'], 'matchday' => 4, 'status' => 'TIMED',
                'homeTeam' => ['id' => 10 + $i, 'name' => 'Home '.$i], 'awayTeam' => ['id' => 200 + $i, 'name' => 'Away '.$i], 'score' => ['fullTime' => ['home' => null, 'away' => null]]];
        }
        Http::fake(function () use (&$data) {
            return Http::response($data);
        });
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->assertSame(102, app(FixtureImporter::class)->run());
        $this->assertLessThan(25, $queries);
        $this->assertDatabaseCount('fixtures', 102);
        $this->assertDatabaseCount('teams', 202);
        $this->assertNotNull(Fixture::where('external_id', '101')->value('result_recorded_at'));

        $before = Fixture::orderBy('id')->pluck('updated_at', 'external_id');
        $this->travel(1)->hours();
        Cache::flush();
        $data['matches'][0]['utcDate'] = now()->addDays(4)->toIso8601String();
        app(FixtureImporter::class)->run();
        $after = Fixture::orderBy('id')->pluck('updated_at', 'external_id');
        $this->assertNotEquals($before['100'], $after['100']);
        $this->assertEquals($before->except('100'), $after->except('100'));
    }

    public function test_filter_options_are_cached_and_refreshed_after_changes(): void
    {
        $this->seed();
        $count = count($this->getJson('/api/v1/fixtures/filter-options')->assertOk()->json('data'));
        $fixture = Fixture::firstOrFail();
        Fixture::create([...$fixture->only(['league_id', 'home_team_id', 'away_team_id']), 'kickoff_at' => now()->addDays(30), 'season' => '2099', 'matchday' => 7]);
        $this->assertCount($count + 1, $this->getJson('/api/v1/fixtures/filter-options')->json('data'));
        DB::table('fixtures')->where('season', '2099')->delete();
        $this->assertCount($count + 1, $this->getJson('/api/v1/fixtures/filter-options')->json('data'));
    }

    public function test_malformed_batch_is_rejected_before_any_domain_writes(): void
    {
        config(['football.data_token' => 'secret']);
        $data = $this->payload();
        $data['matches'][1]['homeTeam']['id'] = 1;
        Http::fake(['*' => Http::response($data)]);
        try {
            app(FixtureImporter::class)->run();
            $this->fail('Expected invalid data.');
        } catch (ProviderUnavailable) {
            $this->assertDatabaseCount('fixtures', 0);
            $this->assertDatabaseCount('leagues', 0);
        }
    }

    public function test_sync_requires_configuration_and_admin_permissions(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/fixtures/sync')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/api/v1/fixtures/sync')->assertConflict();
        config(['football.data_token' => 'secret']);
        $this->postJson('/api/v1/fixtures/sync')->assertAccepted();
        Queue::assertPushed(SyncFixtures::class);
    }

    public function test_sync_job_runs_and_terminal_replay_does_not_import_again(): void
    {
        config(['football.data_token' => 'secret']);
        Http::fake(['*' => Http::response($this->payload())]);
        $sync = FixtureSync::create(['status' => 'pending']);
        $job = new SyncFixtures($sync->id);
        $job->handle(app(FixtureImporter::class));
        $job->handle(app(FixtureImporter::class));
        $this->assertSame('completed', $sync->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_invalid_future_result_does_not_create_records(): void
    {
        config(['football.data_token' => 'secret']);
        $data = $this->payload();
        $data['matches'][1]['utcDate'] = now()->addDay()->toIso8601String();
        Http::fake(['*' => Http::response($data)]);
        try {
            app(FixtureImporter::class)->run();
            $this->fail('Expected future result rejection.');
        } catch (ProviderUnavailable) {
            $this->assertDatabaseCount('fixtures', 0);
        }
    }
}
