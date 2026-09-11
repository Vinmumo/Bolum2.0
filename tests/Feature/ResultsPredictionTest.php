<?php

namespace Tests\Feature;

use App\Jobs\GeneratePrediction;
use App\Models\Company;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Prediction;
use App\Models\Provider;
use App\Models\User;
use App\Services\PredictionCalculator;
use App\Services\Providers\ResultsFootballProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResultsPredictionTest extends TestCase
{
    use RefreshDatabase;

    private Fixture $fixture;

    private Company $company;

    private array $historyIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-11 12:00:00'));
        $this->seed();
        Queue::fake();
        $this->company = Company::where('name', 'Bolum Demo')->firstOrFail();
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->firstOrFail());
        Provider::query()->update(['is_active' => false]);
        Provider::create(['name' => 'Results', 'driver' => 'results', 'weight' => 1, 'is_active' => true]);
        $this->fixture = Fixture::firstOrFail();
        $this->fixture->update(['source' => 'football-data', 'kickoff_at' => now()->addDay()]);
        $this->fixture->league->update(['source' => 'football-data', 'external_id' => '2021']);
        // Constant score rates give a known numerical baseline independent of decay weights.
        for ($i = 0; $i < 20; $i++) {
            $match = Fixture::create([...$this->fixture->only(['league_id', 'home_team_id', 'away_team_id']),
                'source' => 'football-data', 'status' => 'finished', 'is_finished' => true,
                'kickoff_at' => now()->subDays($i + 2), 'home_goals' => 2, 'away_goals' => 1,
                'result_recorded_at' => now()->subDays($i + 1)]);
            $match->forceFill(['updated_at' => now()->subDays($i + 1)])->save();
            $this->historyIds[] = $match->id;
        }
    }

    private function url(): string
    {
        return '/api/v1/companies/'.$this->company->id.'/fixtures/'.$this->fixture->id.'/predictions';
    }

    public function test_real_results_inputs_are_frozen_before_debit_and_survive_later_changes(): void
    {
        $response = $this->postJson($this->url(), [], ['Idempotency-Key' => 'frozen'])->assertAccepted();
        $prediction = Prediction::findOrFail($response->json('data.id'));
        $inputs = $prediction->fixture_snapshot['results_inputs'];
        $this->assertSame(20, $inputs['league_matches']);
        $this->assertEqualsWithDelta(2, $inputs['home'], 0.000001);
        $this->assertEqualsWithDelta(1, $inputs['away'], 0.000001);
        $this->assertSame($this->historyIds, $inputs['fixture_ids']);
        Fixture::whereIn('id', $this->historyIds)->update(['home_goals' => 8, 'away_goals' => 7, 'result_recorded_at' => now()->addHour()]);
        Provider::query()->update(['is_active' => false, 'weight' => 4]);
        $this->postJson($this->url(), [], ['Idempotency-Key' => 'frozen'])->assertOk()->assertJsonPath('data.id', $prediction->id);
        (new GeneratePrediction($this->company->id, $prediction->id))->handle(app(PredictionCalculator::class));
        $result = $prediction->fresh()->result;
        $this->assertSame('external', $result['data_quality']);
        $this->assertEqualsWithDelta(2, $result['expected_goals']['home'], 0.000001);
        $this->assertSame($inputs, $result['sources'][0]['evidence']);
        $this->assertEqualsWithDelta(1, array_sum($result['probabilities']), 0.000001);
        $this->assertSame(9, $this->company->fresh()->credits);
        $this->assertDatabaseCount('predictions', 1);
        Queue::assertPushed(GeneratePrediction::class, 1);
    }

    public function test_model_excludes_future_unknown_stale_and_other_league_results(): void
    {
        $other = League::create(['name' => 'Another league', 'country' => 'England']);
        $base = Fixture::findOrFail($this->historyIds[0])->only(['league_id', 'home_team_id', 'away_team_id', 'source', 'status', 'is_finished', 'kickoff_at', 'home_goals', 'away_goals', 'result_recorded_at']);
        foreach ([['kickoff_at' => now()->addHour()], ['result_recorded_at' => null], ['result_recorded_at' => now()->addHour()], ['updated_at' => now()->addHour()], ['source' => null], ['league_id' => $other->id], ['kickoff_at' => now()->subDays(366)], ['is_finished' => false], ['home_goals' => null]] as $changes) {
            (new Fixture)->forceFill([...$base, 'home_goals' => 50, ...$changes])->save();
        }
        $snapshot = app(ResultsFootballProvider::class)->snapshot($this->fixture, CarbonImmutable::now());
        $this->assertSame($this->historyIds, $snapshot['fixture_ids']);
        $this->assertEqualsWithDelta(2, $snapshot['home'], 0.000001);
    }

    public function test_rates_use_venue_specific_attack_and_defence_with_league_smoothing(): void
    {
        Fixture::whereIn('id', $this->historyIds)->update(['kickoff_at' => now()->subDays(2)]);
        Fixture::whereIn('id', array_slice($this->historyIds, 10))->update([
            'home_team_id' => $this->fixture->away_team_id, 'away_team_id' => $this->fixture->home_team_id,
            'home_goals' => 4, 'away_goals' => 0,
        ]);
        $inputs = app(ResultsFootballProvider::class)->snapshot($this->fixture, CarbonImmutable::now());
        $weight = 0.5 ** (2 / 90);
        $homeRate = (10 * 2 * $weight + 5 * 3) / (10 * $weight + 5);
        $awayRate = (10 * $weight + 5 * 0.5) / (10 * $weight + 5);
        $this->assertSame(10, $inputs['home_venue_matches']);
        $this->assertSame(10, $inputs['away_venue_matches']);
        $this->assertEqualsWithDelta($homeRate ** 2 / 3, $inputs['home'], 0.000001);
        $this->assertEqualsWithDelta($awayRate ** 2 / 0.5, $inputs['away'], 0.000001);
        $this->assertFalse($inputs['limited_sample']);
    }

    public function test_team_minimum_is_required_even_with_enough_league_matches(): void
    {
        $teams = $this->fixture->league->teams()->whereNotIn('id', [$this->fixture->home_team_id, $this->fixture->away_team_id])->get();
        Fixture::whereIn('id', array_slice($this->historyIds, 2))->update(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
        $this->postJson($this->url(), [], ['Idempotency-Key' => 'team-minimum'])->assertConflict()->assertSee('3 matches per team');
        $this->assertSame(10, $this->company->fresh()->credits);
        $this->assertDatabaseCount('predictions', 0);
    }

    public function test_insufficient_history_or_local_fixture_never_spends_a_credit(): void
    {
        Fixture::whereKey($this->historyIds[0])->delete();
        $this->postJson($this->url(), [], ['Idempotency-Key' => 'too-few'])->assertConflict()->assertSee('20 league matches');
        $this->fixture->update(['source' => null]);
        $this->postJson($this->url(), [], ['Idempotency-Key' => 'local'])->assertConflict()->assertSee('upcoming imported fixture');
        $this->assertSame(10, $this->company->fresh()->credits);
        $this->assertDatabaseCount('predictions', 0);
        $this->assertDatabaseMissing('credit_entries', ['kind' => 'prediction_debit']);
        Queue::assertNothingPushed();
    }

    public function test_recent_form_is_latest_first_and_scores_follow_each_teams_perspective(): void
    {
        $this->getJson('/api/v1/fixtures/'.$this->fixture->id)->assertOk()
            ->assertJsonCount(5, 'form.home')->assertJsonCount(5, 'form.away')
            ->assertJsonPath('form.home.0.fixture_id', $this->historyIds[0])
            ->assertJsonPath('form.home.0.outcome', 'W')->assertJsonPath('form.home.0.venue', 'home')
            ->assertJsonPath('form.away.0.outcome', 'L')->assertJsonPath('form.away.0.goals_for', 1);
        $this->fixture->update(['kickoff_at' => now()->subDays(10), 'status' => 'finished', 'is_finished' => true]);
        $this->getJson('/api/v1/fixtures/'.$this->fixture->id)->assertOk()->assertJsonPath('form.home.0.fixture_id', $this->historyIds[9]);
        $this->fixture->update(['source' => null]);
        $this->getJson('/api/v1/fixtures/'.$this->fixture->id)->assertOk()->assertJsonCount(0, 'form.home');
    }

    public function test_command_enables_results_and_pauses_samples_without_resetting_http_providers(): void
    {
        Provider::where('driver', 'results')->delete();
        Provider::where('driver', 'sample')->update(['is_active' => true]);
        Provider::create(['name' => 'Gateway', 'driver' => 'http', 'weight' => 2, 'is_active' => false]);
        $this->artisan('predictions:use-results')->assertSuccessful();
        $this->artisan('predictions:use-results')->assertSuccessful();
        $this->assertSame(1, Provider::where('driver', 'results')->count());
        $this->assertDatabaseHas('providers', ['driver' => 'results', 'is_active' => true]);
        $this->assertDatabaseHas('providers', ['driver' => 'sample', 'is_active' => false]);
        $this->assertDatabaseHas('providers', ['driver' => 'http', 'is_active' => false, 'weight' => 2]);
    }
}
