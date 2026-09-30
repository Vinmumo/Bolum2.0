<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_requests_are_rate_limited(): void
    {
        config(['app.api_rate_limit' => 2]);
        $this->getJson('/api/v1/leagues')->assertOk();
        $this->getJson('/api/v1/fixtures')->assertOk();
        $this->getJson('/api/v1/fixtures/filter-options')->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_login_attempts_are_limited_per_account_and_address(): void
    {
        $this->seed();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'member@bolum.test', 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'MEMBER@bolum.test', 'password' => 'wrong-password'])->assertTooManyRequests();
        // Another account from the same address still has its own allowance within the per-IP cap.
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@bolum.test', 'password' => 'password123'])->assertOk();
    }
}
