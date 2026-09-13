<?php

namespace Tests\Feature;

use App\Actions\Auth\RegisterUserAction;
use App\Data\RegisterUserData;
use App\Models\Company;
use App\Models\CreditEntry;
use App\Models\Fixture;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_action_rolls_back_the_entire_workspace_when_the_grant_fails(): void
    {
        CreditEntry::creating(fn () => throw new RuntimeException('Ledger unavailable.'));
        try {
            app(RegisterUserAction::class)->execute(new RegisterUserData('Alex', 'alex@example.test', 'strong-password', 'Alex Workspace'));
            $this->fail('Expected the grant to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Ledger unavailable.', $error->getMessage());
            foreach (['users', 'companies', 'company_user', 'credit_entries'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        } finally {
            CreditEntry::flushEventListeners();
        }
    }

    public function test_api_and_browser_registration_share_ownership_and_credit_rules(): void
    {
        foreach (['/api/v1/auth/register', '/session/register'] as $index => $url) {
            $email = 'registration-'.$index.'@example.test';
            $response = $this->postJson($url, ['name' => 'Alex', 'email' => $email, 'password' => 'strong-password',
                'password_confirmation' => 'strong-password', 'company_name' => 'Workspace '.$index, 'is_admin' => true]);
            $response->assertCreated();
            $user = User::where('email', $email)->firstOrFail();
            $company = $user->companies()->firstOrFail();
            $this->assertFalse($user->is_admin);
            $this->assertSame('owner', $company->pivot->role);
            $this->assertSame(10, $company->credits);
            $this->assertSame(1, $company->creditEntries()->count());
            $this->assertSame(10, (int) $company->creditEntries()->sum('amount'));
            if ($index === 0) {
                $response->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email'], 'companies' => [['id', 'name']]]])
                    ->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.user.data')
                    ->assertJsonPath('data.companies.0.pivot.role', 'owner');
            } else {
                $response->assertJsonMissingPath('data.token')->assertJsonStructure(['csrf_token']);
            }
        }
        $this->assertSame(2, Company::count());
    }

    public function test_login_and_me_preserve_the_membership_role_without_exposing_company_columns(): void
    {
        $this->seed();
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'member@bolum.test', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('data.companies.0.pivot.role', 'member')->assertJsonMissingPath('data.companies.0.credits');
        $this->withToken($login->json('data.token'))->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.companies.0.pivot.role', 'member')->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.companies.0.credits');
    }

    public function test_partial_fixture_update_keeps_omitted_values_and_applies_explicit_false(): void
    {
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@bolum.test')->firstOrFail());
        $fixture = Fixture::firstOrFail();
        $fixture->update(['is_finished' => true]);
        $before = $fixture->only(['league_id', 'home_team_id', 'away_team_id', 'kickoff_at']);
        $this->patchJson('/api/v1/fixtures/'.$fixture->id, ['is_finished' => false])->assertOk()->assertJsonPath('data.is_finished', false);
        $this->assertEquals($before, $fixture->fresh()->only(array_keys($before)));
        $this->patchJson('/api/v1/fixtures/'.$fixture->id, ['home_team_id' => null])->assertUnprocessable();
    }

    public function test_provider_updates_distinguish_omitted_active_state_from_false(): void
    {
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@bolum.test')->firstOrFail());
        $provider = Provider::firstOrFail();
        $data = ['name' => $provider->name, 'driver' => $provider->driver, 'weight' => 2];
        $this->putJson('/api/v1/providers/'.$provider->id, [...$data, 'is_active' => false])->assertOk();
        $this->assertFalse($provider->fresh()->is_active);
        $this->putJson('/api/v1/providers/'.$provider->id, [...$data, 'weight' => 3])->assertOk();
        $this->assertFalse($provider->fresh()->is_active);
        $this->assertSame(3.0, (float) $provider->fresh()->weight);
    }

    public function test_record_result_preserves_zero_scores_through_the_data_object(): void
    {
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@bolum.test')->firstOrFail());
        $fixture = Fixture::firstOrFail();
        $fixture->update(['kickoff_at' => now()->subHours(3)]);
        $this->putJson('/api/v1/fixtures/'.$fixture->id.'/result', ['home_goals' => 0, 'away_goals' => 0])
            ->assertOk()->assertJsonPath('data.score.home', 0)->assertJsonPath('data.score.away', 0)->assertJsonPath('data.status', 'finished');
        $this->assertNotNull($fixture->fresh()->result_recorded_at);
    }
}
