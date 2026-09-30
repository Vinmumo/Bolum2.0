<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_and_permission_errors_are_readable_and_keep_their_status_codes(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertExactJson(['message' => 'Please sign in to continue.']);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/overview')->assertForbidden()->assertExactJson(['message' => "You don't have permission to do that."]);
        $this->getJson('/api/v1/fixtures/99999')->assertNotFound()->assertJsonPath('message', "We couldn't find what you requested. It may have been removed.");
    }

    public function test_unexpected_errors_hide_internal_details_even_when_debug_is_enabled(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/error-example', fn () => throw new RuntimeException('SQLSTATE password=private /srv/internal.php'));
        $this->getJson('/api/error-example')->assertStatus(500)
            ->assertExactJson(['message' => 'Something went wrong on our side. Please try again.'])
            ->assertDontSee('SQLSTATE')->assertJsonMissingPath('trace')->assertJsonMissingPath('exception');
    }

    public function test_validation_keeps_field_errors_for_inline_form_feedback(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable()
            ->assertJsonPath('message', 'Please check the highlighted fields and try again.')
            ->assertJsonValidationErrors(['name', 'email', 'password', 'company_name']);
    }

    public function test_retry_headers_and_actionable_business_conflicts_are_preserved(): void
    {
        Route::get('/api/rate-example', fn () => abort(429, 'Too Many Attempts.', ['Retry-After' => '15']));
        $this->getJson('/api/rate-example')->assertStatus(429)->assertHeader('Retry-After', '15')
            ->assertJsonPath('message', "You're making requests too quickly. Wait a moment and try again.");
        Route::get('/api/conflict-example', fn () => abort(409, 'This fixture already has a final result.'));
        $this->getJson('/api/conflict-example')->assertConflict()->assertJsonPath('message', 'This fixture already has a final result.');
    }
}
