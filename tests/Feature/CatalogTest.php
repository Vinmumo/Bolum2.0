<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_owner_and_revocable_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Alex', 'email' => 'alex@example.test', 'password' => 'strong-password', 'password_confirmation' => 'strong-password', 'company_name' => 'Alex Analytics'])->assertCreated();
        $token = $response->json('data.token');
        $this->assertDatabaseHas('company_user', ['user_id' => 1, 'role' => 'owner']);
        $this->assertDatabaseHas('users', ['email' => 'alex@example.test', 'is_admin' => false]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rejects_bad_credentials_and_accepts_correct_password(): void
    {
        User::factory()->create(['email' => 'a@example.test', 'password' => 'secret-password']);
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'secret-password'])->assertOk()->assertJsonStructure(['data' => ['token', 'user', 'companies']]);
    }

    public function test_only_admin_can_manage_catalog(): void
    {
        $data = ['name' => 'Premier', 'country' => 'England'];
        $this->postJson('/api/v1/leagues', $data)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/leagues', $data)->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/api/v1/leagues', $data)->assertCreated();
    }

    public function test_fixture_validation_uses_effective_values_on_partial_update(): void
    {
        $this->seed();
        Sanctum::actingAs(User::where('is_admin', true)->first());
        $fixture = Fixture::first();
        $this->patchJson('/api/v1/fixtures/'.$fixture->id, ['away_team_id' => $fixture->home_team_id])->assertUnprocessable()->assertJsonValidationErrors('away_team_id');
        $league = League::create(['name' => 'Other', 'country' => 'Kenya']);
        $team = Team::create(['league_id' => $league->id, 'name' => 'Other team']);
        $this->patchJson('/api/v1/fixtures/'.$fixture->id, ['away_team_id' => $team->id])->assertUnprocessable();
        $this->patchJson('/api/v1/fixtures/'.$fixture->id, ['is_finished' => true])->assertOk()->assertJsonPath('data.is_finished', true);
    }

    public function test_fixture_list_is_paginated_eager_loaded_and_bounded(): void
    {
        $this->seed();
        $this->getJson('/api/v1/fixtures?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4)->assertJsonStructure(['data' => [['home_team' => ['name'], 'away_team' => ['name'], 'league' => ['name']]], 'links', 'meta']);
        $this->getJson('/api/v1/fixtures?per_page=10000')->assertUnprocessable();
    }

    public function test_provider_weight_must_be_positive(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $this->postJson('/api/v1/providers', ['name' => 'Bad', 'driver' => 'sample', 'weight' => 0])->assertUnprocessable()->assertJsonValidationErrors('weight');
    }
}
