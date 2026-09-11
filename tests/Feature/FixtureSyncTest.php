<?php

namespace Tests\Feature;

use App\Exceptions\ProviderUnavailable;
use App\Jobs\SyncFixtures;
use App\Models\FixtureSync;
use App\Models\User;
use App\Services\FixtureImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FixtureSyncTest extends TestCase
{
    use RefreshDatabase;

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
        Http::assertSent(fn ($r) => $r->hasHeader('X-Auth-Token', 'secret') && str_contains($r->url(), '/competitions/PL/matches'));
        Http::assertSentCount(1);
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
