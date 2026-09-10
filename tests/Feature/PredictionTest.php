<?php

namespace Tests\Feature;

use App\Enums\PredictionStatus;
use App\Jobs\GeneratePrediction;
use App\Models\Company;
use App\Models\CreditEntry;
use App\Models\Fixture;
use App\Models\Prediction;
use App\Models\Provider;
use App\Models\User;
use App\Services\PredictionCalculator;
use App\Services\PredictionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PredictionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $member;

    private Fixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
        $this->company = Company::where('name', 'Bolum Demo')->first();
        $this->member = User::where('email', 'member@bolum.test')->first();
        $this->fixture = Fixture::first();
        Sanctum::actingAs($this->member);
    }

    private function url(?Fixture $fixture = null): string
    {
        return '/api/v1/companies/'.$this->company->id.'/fixtures/'.($fixture ?? $this->fixture)->id.'/predictions';
    }

    private function requestPrediction(string $key = 'request-1')
    {
        return $this->postJson($this->url(), [], ['Idempotency-Key' => $key]);
    }

    public function test_request_debits_once_and_queues_after_commit(): void
    {
        $r = $this->requestPrediction()->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->requestPrediction()->assertOk()->assertJsonPath('data.id', $r->json('data.id'));
        $this->assertSame(9, $this->company->fresh()->credits);
        $this->assertDatabaseCount('predictions', 1);
        $this->assertDatabaseHas('credit_entries', ['kind' => 'prediction_debit', 'amount' => -1]);
        Queue::assertPushed(GeneratePrediction::class, fn ($job) => $job->afterCommit === true && $job->companyId === $this->company->id);
        Queue::assertPushed(GeneratePrediction::class, 1);
    }

    public function test_conflicting_key_is_rejected_without_extra_debit(): void
    {
        $this->requestPrediction()->assertAccepted();
        $fixture = Fixture::whereKeyNot($this->fixture->id)->first();
        $this->postJson($this->url($fixture), [], ['Idempotency-Key' => 'request-1'])->assertConflict();
        $this->assertSame(9, $this->company->fresh()->credits);
    }

    public function test_validation_and_insufficient_credits_do_not_queue_work(): void
    {
        $this->postJson($this->url())->assertUnprocessable();
        $this->company->forceFill(['credits' => 0])->save();
        $this->requestPrediction()->assertConflict();
        $this->assertDatabaseCount('predictions', 0);
        Queue::assertNothingPushed();
    }

    public function test_finished_fixtures_and_no_providers_are_rejected(): void
    {
        $this->fixture->update(['is_finished' => true]);
        $this->requestPrediction()->assertConflict();
        $this->fixture->update(['is_finished' => false]);
        Provider::query()->update(['is_active' => false]);
        $this->requestPrediction()->assertConflict();
        $this->assertSame(10, $this->company->fresh()->credits);
    }

    public function test_membership_and_scoped_bindings_hide_other_company_predictions(): void
    {
        $id = $this->requestPrediction()->assertAccepted()->json('data.id');
        $other = Company::where('name', 'Rival Analytics')->first();
        $base = '/api/v1/companies/'.$other->id;
        $this->getJson($base.'/predictions')->assertForbidden();
        $this->getJson($base.'/credits')->assertForbidden();
        $other->users()->attach($this->member);
        $this->getJson($base.'/predictions/'.$id)->assertNotFound();
        $this->getJson($base.'/predictions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/fixtures/'.$this->fixture->id.'/predictions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/fixtures/'.$this->fixture->id.'/predictions/latest')->assertNotFound();
    }

    public function test_job_is_idempotent_and_preserves_provider_snapshot(): void
    {
        $id = $this->requestPrediction()->assertAccepted()->json('data.id');
        Provider::query()->update(['is_active' => false]);
        $job = new GeneratePrediction($this->company->id, $id);
        $job->handle(app(PredictionCalculator::class));
        $first = Prediction::find($id)->result;
        $job->handle(app(PredictionCalculator::class));
        $this->assertSame($first, Prediction::find($id)->result);
        $this->assertEqualsWithDelta(1, array_sum($first['probabilities']), 0.000001);
        $this->getJson('/api/v1/companies/'.$this->company->id.'/predictions/'.$id)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson($this->url().'/latest')->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(9, $this->company->fresh()->credits);
    }

    public function test_failure_refunds_once_and_a_completed_job_cannot_be_refunded(): void
    {
        $id = $this->requestPrediction()->assertAccepted()->json('data.id');
        $job = new GeneratePrediction($this->company->id, $id);
        $job->failed(new \RuntimeException('secret'));
        $job->failed(new \RuntimeException('secret'));
        $job->handle(app(PredictionCalculator::class));
        $this->assertSame(10, $this->company->fresh()->credits);
        $this->assertSame(PredictionStatus::Failed, Prediction::find($id)->status);
        $this->assertDatabaseHas('credit_entries', ['prediction_id' => $id, 'kind' => 'prediction_refund', 'amount' => 1]);
        $this->assertSame(1, DB::table('credit_entries')->where('kind', 'prediction_refund')->count());
        $this->getJson('/api/v1/companies/'.$this->company->id.'/predictions/'.$id)->assertOk()->assertDontSee('secret');
        $id2 = $this->requestPrediction('request-2')->json('data.id');
        $job2 = new GeneratePrediction($this->company->id, $id2);
        $job2->handle(app(PredictionCalculator::class));
        $job2->failed(null);
        $this->assertSame(9, $this->company->fresh()->credits);
    }

    public function test_job_cannot_process_another_company_prediction(): void
    {
        $id = $this->requestPrediction()->json('data.id');
        $this->expectException(ModelNotFoundException::class);
        (new GeneratePrediction(Company::where('name', 'Rival Analytics')->value('id'), $id))->handle(app(PredictionCalculator::class));
    }

    public function test_credit_topups_require_owner_and_are_idempotent(): void
    {
        $url = '/api/v1/companies/'.$this->company->id.'/credits/top-ups';
        $headers = ['Idempotency-Key' => 'topup-1'];
        $this->postJson($url, ['amount' => 20], $headers)->assertForbidden();
        Sanctum::actingAs(User::where('email', 'admin@bolum.test')->first());
        $this->postJson($url, ['amount' => 20], $headers)->assertCreated();
        $this->postJson($url, ['amount' => 20], $headers)->assertOk();
        $this->postJson($url, ['amount' => 21], $headers)->assertConflict();
        $this->postJson($url, ['amount' => -1], ['Idempotency-Key' => 'bad'])->assertUnprocessable();
        $this->assertSame(30, $this->company->fresh()->credits);
    }

    public function test_database_constraint_and_transaction_rollback_preserve_balance(): void
    {
        // Force the debit ledger insert to violate uniqueness, independent of engine sequences.
        $this->company->creditEntries()->create(['user_id' => $this->member->id, 'idempotency_key' => 'forced-collision', 'kind' => 'test_collision', 'amount' => 0, 'balance_after' => 10]);
        CreditEntry::creating(function ($entry) {
            $entry->idempotency_key = 'forced-collision';
        });
        try {
            app(PredictionService::class)->request($this->company, $this->fixture, $this->member, 'rollback');
            $this->fail('Expected unique constraint violation.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame(10, $this->company->fresh()->credits);
            $this->assertDatabaseCount('predictions', 0);
            Queue::assertNothingPushed();
        } finally {
            CreditEntry::flushEventListeners();
        }
    }

    public function test_recovery_queues_only_stale_pending_work(): void
    {
        $id = $this->requestPrediction()->json('data.id');
        Prediction::whereKey($id)->update(['created_at' => now()->subMinutes(6)]);
        Queue::fake();
        $this->artisan('predictions:recover')->assertSuccessful();
        Queue::assertPushed(GeneratePrediction::class, 1);
    }
}
