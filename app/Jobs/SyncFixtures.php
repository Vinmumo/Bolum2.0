<?php

namespace App\Jobs;

use App\Models\FixtureSync;
use App\Services\FixtureImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncFixtures implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Several competitions share an 8-call/minute budget, so a full sync can take a few minutes. */
    public int $timeout = 300;

    public function __construct(public int $syncId) {}

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(FixtureImporter $importer): void
    {
        $sync = FixtureSync::findOrFail($this->syncId);
        if ($sync->status !== 'pending') {
            return;
        }
        $count = $importer->run();
        $sync->update(['status' => 'completed', 'imported' => $count]);
    }

    public function failed(?\Throwable $exception): void
    {
        FixtureSync::whereKey($this->syncId)->where('status', 'pending')->update(['status' => 'failed', 'error' => 'Synchronization failed. Check provider access and try again.']);
    }
}
