<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_requires_authentication_and_only_updates_the_current_users_allowed_fields(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/profile', ['name' => 'New Name', 'avatar' => 'captain', 'email' => 'changed@example.test', 'is_admin' => true, 'id' => $other->id])
            ->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.avatar', 'captain')->assertJsonPath('data.email', $user->email)->assertJsonPath('data.is_admin', false);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonMissingPath('data.password')->assertJsonStructure(['data' => ['created_at']]);
    }

    public function test_profile_rejects_blank_names_and_arbitrary_avatar_urls(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson('/api/v1/profile', ['name' => '   ', 'avatar' => 'https://example.test/avatar.svg'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'avatar']);
    }

    public function test_password_change_checks_current_password_confirmation_and_strength_before_revoking_access(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $user->createToken('device');
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/profile/password', ['current_password' => 'incorrect', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/profile/password', ['current_password' => 'original-password', 'password' => 'short', 'password_confirmation' => 'different'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->putJson('/api/v1/profile/password', ['current_password' => 'original-password', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertOk();
        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
    }

    public function test_admin_overview_requires_global_admin_and_exposes_counts_without_private_prediction_data(): void
    {
        $this->seed();
        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->firstOrFail());
        $this->getJson('/api/v1/admin/overview')->assertForbidden();
        Sanctum::actingAs(User::where('email', 'admin@bolum.test')->firstOrFail());
        $this->getJson('/api/v1/admin/overview')->assertOk()->assertJsonPath('data.active_providers', 1)->assertJsonPath('data.pending_predictions', 0)->assertDontSee('password')->assertDontSee('provider_snapshot');
    }
}
