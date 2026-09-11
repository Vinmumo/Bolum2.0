<?php

namespace Tests\Feature;

use App\Jobs\GeneratePrediction;
use App\Models\Company;
use App\Models\Fixture;
use App\Models\Prediction;
use App\Models\User;
use App\Services\PredictionCalculator;
use App\Services\PredictionService;
use App\Services\Providers\HttpFootballProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
        $this->company = Company::where('name', 'Bolum Demo')->first();
        $this->user = User::where('email', 'admin@bolum.test')->first();
        Sanctum::actingAs($this->user);
    }

    private function predict(Fixture $fixture, string $key = 'one'): Prediction
    {
        $prediction = app(PredictionService::class)->request($this->company, $fixture, $this->user, $key);
        (new GeneratePrediction($this->company->id, $prediction->id))->handle(app(PredictionCalculator::class));

        return $prediction->fresh();
    }

    public function test_completed_predictions_keep_per_source_results_and_extra_probabilities(): void
    {
        $prediction = $this->predict(Fixture::first());
        $result = $prediction->result;
        $this->assertNotNull($prediction->completed_at);
        $this->assertSame('sample', $result['data_quality']);
        $this->assertCount(1, $result['sources']);
        $this->assertEqualsWithDelta(1, array_sum($result['sources'][0]['probabilities']), 1e-12);
        $this->assertGreaterThan($result['totals']['over_3_5'], $result['totals']['over_2_5']);
        $this->assertGreaterThan($result['totals']['over_2_5'], $result['totals']['over_1_5']);
        $this->assertCount(5, $result['scorelines']);
    }

    public function test_performance_only_counts_latest_eligible_prediction_and_separates_sample_data(): void
    {
        $fixture = Fixture::first();
        $first = $this->predict($fixture);
        $latest = $this->predict($fixture, 'two');
        $this->travel(5)->days();
        $this->putJson('/api/v1/fixtures/'.$fixture->id.'/result', ['home_goals' => 2, 'away_goals' => 0])->assertOk();
        $base = '/api/v1/companies/'.$this->company->id.'/performance';
        $this->getJson($base)->assertOk()->assertJsonPath('data.count', 0);
        $r = $this->getJson($base.'?quality=sample')->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.recent.0.prediction_id', $latest->id);
        $probs = $latest->result['probabilities'];
        $expected = ($probs['home_win'] - 1) ** 2 + $probs['draw'] ** 2 + $probs['away_win'] ** 2;
        $this->assertEqualsWithDelta($expected, $r->json('data.brier_score'), 1e-12);
        $this->assertEqualsWithDelta(-log($probs['home_win']), $r->json('data.log_loss'), 1e-12);
    }

    public function test_results_and_reports_enforce_authorization_and_temporal_rules(): void
    {
        $fixture = Fixture::first();
        $this->putJson('/api/v1/fixtures/'.$fixture->id.'/result', ['home_goals' => 1, 'away_goals' => 0])->assertConflict();
        $this->putJson('/api/v1/fixtures/'.$fixture->id.'/result', ['home_goals' => -1, 'away_goals' => 0])->assertUnprocessable();
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->first());
        $this->putJson('/api/v1/fixtures/'.$fixture->id.'/result', ['home_goals' => 1, 'away_goals' => 0])->assertForbidden();
        $other = Company::where('name', 'Rival Analytics')->first();
        $this->getJson('/api/v1/companies/'.$other->id.'/performance')->assertForbidden();
    }

    public function test_post_kickoff_completion_and_legacy_records_are_not_evaluated(): void
    {
        $fixture = Fixture::first();
        $p = $this->predict($fixture);
        $p->update(['completed_at' => $fixture->kickoff_at->addSecond()]);
        $this->travel(5)->days();
        $this->putJson('/api/v1/fixtures/'.$fixture->id.'/result', ['home_goals' => 1, 'away_goals' => 1])->assertOk();
        $this->getJson('/api/v1/companies/'.$this->company->id.'/performance?quality=sample')->assertJsonPath('data.count', 0);
        $p->update(['completed_at' => null]);
        $this->getJson('/api/v1/companies/'.$this->company->id.'/performance?quality=sample')->assertJsonPath('data.count', 0);
    }

    public function test_provider_usage_is_admin_only_and_does_not_expose_credentials(): void
    {
        config(['football.gateway_url' => 'https://gateway.test/goals', 'football.gateway_token' => 'private-token']);
        Http::fake(['*' => Http::response(['home' => 1.2, 'away' => 0.8])]);
        $provider = app(HttpFootballProvider::class);
        $provider->expectedGoals(['id' => 1]);
        $provider->expectedGoals(['id' => 1]);
        $this->assertDatabaseCount('provider_calls', 2);
        $this->assertDatabaseHas('provider_calls', ['status' => 'cached', 'cache_hit' => true]);
        $this->getJson('/api/v1/providers/usage')->assertOk()->assertDontSee('private-token')->assertDontSee('gateway.test');
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->first());
        $this->getJson('/api/v1/providers/usage')->assertForbidden();
    }

    public function test_demo_refresh_preserves_history_and_balances_and_is_repeatable(): void
    {
        $fixture = Fixture::first();
        $prediction = $this->predict($fixture);
        $original = $fixture->kickoff_at->toIso8601String();
        $this->travel(7)->days();
        $this->artisan('demo:refresh')->assertSuccessful();
        $count = Fixture::count();
        $this->artisan('demo:refresh')->assertSuccessful();
        $this->assertSame($count, Fixture::count());
        $this->assertSame($original, $fixture->fresh()->kickoff_at->toIso8601String());
        $this->assertDatabaseHas('predictions', ['id' => $prediction->id]);
        $this->assertSame(9, $this->company->fresh()->credits);
        $this->assertGreaterThanOrEqual(4, Fixture::upcoming()->count());
    }

    public function test_sample_refresh_is_blocked_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $count = Fixture::count();
        $this->artisan('demo:refresh')->assertExitCode(1);
        $this->assertSame($count, Fixture::count());
    }

    public function test_moving_kickoff_later_does_not_make_a_late_prediction_eligible(): void
    {
        $fixture = Fixture::first();
        $p = $this->predict($fixture);
        $p->update(['completed_at' => $fixture->kickoff_at->addHour()]);
        $fixture->update(['kickoff_at' => $fixture->kickoff_at->addDays(2)]);
        $this->travel(6)->days();
        $this->putJson('/api/v1/fixtures/'.$fixture->id.'/result', ['home_goals' => 1, 'away_goals' => 1])->assertOk();
        $this->getJson('/api/v1/companies/'.$this->company->id.'/performance?quality=sample')->assertJsonPath('data.count', 0);
    }
}
