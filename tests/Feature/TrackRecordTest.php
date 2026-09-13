<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Fixture;
use App\Models\Prediction;
use App\Models\User;
use App\Services\PredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackRecordTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
        $this->company = Company::where('name', 'Bolum Demo')->firstOrFail();
        $this->admin = User::where('email', 'admin@bolum.test')->firstOrFail();
        Sanctum::actingAs($this->admin);
        Fixture::query()->update(['season' => '2026', 'matchday' => 3]);
    }

    private function forecast(Fixture $fixture, string $key, array $probabilities, string $quality = 'external'): Prediction
    {
        $prediction = app(PredictionService::class)->request($this->company, $fixture, $this->admin, $key);
        $prediction->update(['status' => 'completed', 'completed_at' => now(), 'result' => ['data_quality' => $quality, 'probabilities' => $probabilities, 'most_likely_score' => ['home' => 2, 'away' => 0]]]);

        return $prediction;
    }

    private function url(?Company $company = null): string
    {
        return '/api/v1/companies/'.($company ?? $this->company)->id.'/track-record?league_id='.Fixture::firstOrFail()->league_id.'&season=2026&matchday=3';
    }

    public function test_gameweek_counts_saved_pre_kickoff_forecasts_without_inventing_missing_predictions(): void
    {
        $fixtures = Fixture::orderBy('id')->get();
        $this->forecast($fixtures[0], 'old', ['home_win' => .1, 'draw' => .2, 'away_win' => .7]);
        $latest = $this->forecast($fixtures[0], 'latest', ['home_win' => .7, 'draw' => .2, 'away_win' => .1]);
        $this->forecast($fixtures[1], 'wrong', ['home_win' => .7, 'draw' => .2, 'away_win' => .1]);
        $late = $this->forecast($fixtures[2], 'late', ['home_win' => .7, 'draw' => .2, 'away_win' => .1]);
        $late->update(['completed_at' => $fixtures[2]->kickoff_at->addMinute()]);
        $this->forecast($fixtures[3], 'sample', ['home_win' => .7, 'draw' => .2, 'away_win' => .1], 'sample');
        $this->travel(8)->days();
        foreach ($fixtures as $index => $fixture) {
            $fixture->update(['status' => 'finished', 'is_finished' => true, 'home_goals' => $index === 1 ? 0 : 2, 'away_goals' => $index === 1 ? 2 : 0]);
        }
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.finished', 4)->assertJsonPath('data.evaluated', 2)
            ->assertJsonPath('data.correct', 1)->assertJsonPath('data.accuracy', .5)->assertJsonPath('data.missing_forecasts', 2)
            ->assertJsonPath('data.rows.0.forecast.id', $latest->id)->assertJsonPath('data.rows.2.forecast', null);
        $this->getJson($this->url().'&quality=sample')->assertOk()->assertJsonPath('data.evaluated', 1)->assertJsonPath('data.correct', 1);
        $this->assertDatabaseCount('predictions', 5);
        $other = Company::where('name', 'Rival Analytics')->firstOrFail();
        $other->users()->attach($this->admin, ['role' => 'member']);
        $this->getJson($this->url($other))->assertOk()->assertJsonPath('data.evaluated', 0)->assertJsonPath('data.rows.0.forecast', null);
    }

    public function test_reports_enforce_admin_and_workspace_membership_and_do_not_return_zero_accuracy_for_missing_forecasts(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.evaluated', 0)->assertJsonPath('data.accuracy', null);
        $other = Company::where('name', 'Rival Analytics')->firstOrFail();
        $this->getJson($this->url($other))->assertForbidden();
        $other->users()->attach($this->admin, ['role' => 'member']);
        $this->getJson($this->url($other))->assertOk()->assertJsonPath('data.evaluated', 0);
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->firstOrFail());
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_matchday_and_season_filtering_and_metadata_reflect_actual_fixtures(): void
    {
        Fixture::firstOrFail()->update(['matchday' => 4]);
        $this->getJson('/api/v1/fixtures?matchday=4&season=2026')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/fixtures?matchday=0')->assertUnprocessable();
        $this->getJson('/api/v1/fixtures/filter-options')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.matchday', 3)->assertJsonPath('data.0.fixtures', 3);
        $this->getJson($this->url().'&quality=invalid')->assertUnprocessable();
    }
}
