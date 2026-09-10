<?php

use App\Jobs\GeneratePrediction;
use App\Models\Prediction;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recover the small commit-to-dispatch gap if a process dies after committing a request.
Artisan::command('predictions:recover', function () {
    Prediction::where('status', 'pending')->where('created_at', '<', now()->subMinutes(5))->chunkById(100, function ($predictions) {
        foreach ($predictions as $prediction) {
            GeneratePrediction::dispatch($prediction->company_id, $prediction->id);
        }
    });
    $this->info('Stale pending predictions queued for recovery.');
});
Schedule::command('predictions:recover')->everyFiveMinutes()->withoutOverlapping();
