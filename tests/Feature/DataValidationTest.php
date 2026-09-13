<?php

namespace Tests\Feature;

use App\Data\ProviderData;
use App\Data\UpdateFixtureData;
use App\Models\Company;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

class DataValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_properties_are_omitted_from_transformation_without_losing_false(): void
    {
        // Trusted construction bypasses HTTP validation, as it does in Actions/seeders.
        $fixture = new UpdateFixtureData(is_finished: false);
        $this->assertInstanceOf(Optional::class, $fixture->league_id);
        $this->assertSame(['is_finished' => false], $fixture->toArray());

        $provider = ProviderData::validateAndCreate(['name' => 'Example', 'driver' => 'sample', 'weight' => '2.5']);
        $this->assertInstanceOf(Optional::class, $provider->is_active);
        $this->assertSame(['name' => 'Example', 'driver' => 'sample', 'weight' => 2.5], $provider->toArray());
    }

    public function test_registration_confirmation_is_validated_before_creating_any_records(): void
    {
        foreach (['/api/v1/auth/register', '/session/register'] as $url) {
            $this->postJson($url, [
                'name' => 'Alex', 'email' => 'alex@example.test', 'company_name' => 'Alex Workspace',
                'password' => 'strong-password', 'password_confirmation' => 'different-password',
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('credit_entries', 0);
    }

    public function test_permissions_are_checked_before_data_validation(): void
    {
        $this->seed();
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->firstOrFail());
        $fixture = Fixture::firstOrFail();
        $company = Company::firstOrFail();

        $this->postJson('/api/v1/leagues', [])->assertForbidden();
        $this->postJson('/api/v1/fixtures', [])->assertForbidden();
        $this->patchJson('/api/v1/fixtures/'.$fixture->id, ['home_team_id' => null])->assertForbidden();
        $this->postJson('/api/v1/companies/'.$company->id.'/credits/top-ups', ['amount' => -1])->assertForbidden();
    }

    public function test_catalog_uniqueness_is_scoped_to_the_selected_league(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $first = League::create(['name' => 'First', 'country' => 'Kenya']);
        $second = League::create(['name' => 'Second', 'country' => 'Kenya']);
        Team::create(['league_id' => $first->id, 'name' => 'United']);

        $this->postJson('/api/v1/teams', ['league_id' => $first->id, 'name' => 'United'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/teams', ['league_id' => $second->id, 'name' => 'United'])->assertCreated();
    }

    public function test_partial_updates_validate_existing_teams_against_a_changed_league(): void
    {
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@bolum.test')->firstOrFail());
        $fixture = Fixture::firstOrFail();
        $otherLeague = League::create(['name' => 'Other', 'country' => 'Kenya']);

        $this->patchJson('/api/v1/fixtures/'.$fixture->id, ['league_id' => $otherLeague->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['home_team_id', 'away_team_id']);
        $this->assertSame($fixture->league_id, $fixture->fresh()->league_id);
        $this->patchJson('/api/v1/fixtures/'.$fixture->id, ['is_finished' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('is_finished');
    }

    public function test_idempotency_headers_remain_authoritative_over_body_fields(): void
    {
        Queue::fake();
        $this->seed();
        Sanctum::actingAs(User::where('email', 'admin@bolum.test')->firstOrFail());
        $company = Company::firstOrFail();
        $fixture = Fixture::firstOrFail();
        $prefix = '/api/v1/companies/'.$company->id;

        $this->postJson($prefix.'/fixtures/'.$fixture->id.'/predictions', ['idempotency_key' => 'body-only'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson($prefix.'/credits/top-ups', ['amount' => 5, 'idempotency_key' => 'body-only'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(10, $company->fresh()->credits);

        $this->postJson($prefix.'/fixtures/'.$fixture->id.'/predictions', ['idempotency_key' => 'body-key'], ['Idempotency-Key' => 'prediction-header'])
            ->assertAccepted();
        $this->assertDatabaseHas('predictions', ['company_id' => $company->id, 'idempotency_key' => 'prediction-header']);
        $this->postJson($prefix.'/credits/top-ups', ['amount' => '5', 'idempotency_key' => 'body-key'], ['Idempotency-Key' => 'credit-header'])
            ->assertCreated();
        $this->assertDatabaseHas('credit_entries', ['company_id' => $company->id, 'idempotency_key' => 'topup:credit-header', 'amount' => 5]);
        $this->assertSame(14, $company->fresh()->credits);
    }
}
