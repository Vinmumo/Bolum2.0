<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_public_and_contains_cookie_login(): void
    {
        $this->get('/')->assertOk()->assertSee('A better view of the game')->assertSee('csrf-token')->assertSee('/assets/dashboard.js');
    }

    public function test_session_login_grants_api_access_without_creating_a_token(): void
    {
        $this->seed();
        $this->postJson('/session/login', ['email' => 'member@bolum.test', 'password' => 'password123'])->assertOk()->assertJsonStructure(['csrf_token']);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.is_admin', false);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/session/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_browser_registration_creates_company_owner_and_opening_ledger(): void
    {
        $this->postJson('/session/register', ['name' => 'Alice', 'company_name' => 'Alice Analytics', 'email' => 'alice@example.test', 'password' => 'long-password', 'password_confirmation' => 'long-password'])->assertCreated();
        $this->assertAuthenticated();
        $company = Company::first();
        $this->assertSame(10, $company->credits);
        $this->assertSame(10, (int) $company->creditEntries()->sum('amount'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertFalse(User::first()->is_admin);
    }

    public function test_bad_browser_password_is_rejected(): void
    {
        $this->seed();
        $this->postJson('/session/login', ['email' => 'admin@bolum.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->assertGuest();
    }
}
