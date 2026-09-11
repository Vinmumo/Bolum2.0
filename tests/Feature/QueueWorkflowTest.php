<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Fixture;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueueWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    public function test_real_database_queue_processes_committed_prediction(): void
    {
        config(['queue.default' => 'database']);
        $this->seed();
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->first());
        $company = Company::where('name', 'Bolum Demo')->first();
        $url = '/api/v1/companies/'.$company->id.'/fixtures/'.Fixture::first()->id.'/predictions';
        $id = $this->postJson($url, [], ['Idempotency-Key' => 'real-worker'])->assertAccepted()->json('data.id');
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3])->assertSuccessful();
        $this->assertDatabaseHas('predictions', ['id' => $id, 'status' => 'completed']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame($company->fresh()->credits, (int) $company->creditEntries()->sum('amount'));
    }

    public function test_real_worker_records_failure_and_refunds_exhausted_job(): void
    {
        config(['queue.default' => 'database', 'football.gateway_url' => 'https://football.example.test/goals']);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->seed();
        Provider::query()->update(['driver' => 'http']);
        Sanctum::actingAs(User::where('email', 'member@bolum.test')->first());
        $company = Company::where('name', 'Bolum Demo')->first();
        $id = $this->postJson('/api/v1/companies/'.$company->id.'/fixtures/'.Fixture::first()->id.'/predictions', [], ['Idempotency-Key' => 'failed-worker'])->assertAccepted()->json('data.id');
        // Advance attempts and release delay, exercising the actual three-attempt worker lifecycle.
        for ($i = 0; $i < 3; $i++) {
            DB::table('jobs')->update(['available_at' => now()->timestamp]);
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
        }
        $this->assertDatabaseHas('predictions', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(10, $company->fresh()->credits);
    }
}
