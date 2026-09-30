<?php

use App\Exceptions\ProviderUnavailable;
use App\Jobs\GeneratePrediction;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Prediction;
use App\Models\ProviderCall;
use App\Services\FixtureImporter;
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

Artisan::command('fixtures:sync {--recent : Only matches from '.FixtureImporter::RECENT_DAYS_BACK.' days ago to '.FixtureImporter::RECENT_DAYS_AHEAD.' days ahead} {--season= : Season start year, e.g. 2025}', function (FixtureImporter $importer) {
    try {
        $season = $this->option('season');
        if ($season !== null && ! preg_match('/^\d{4}$/', $season)) {
            $this->error('Season must be a four-digit start year, e.g. 2025.');

            return 1;
        }
        $this->info('Imported '.$importer->run($season === null ? null : (int) $season, (bool) $this->option('recent')).' fixtures.');
    } catch (ProviderUnavailable $error) {
        $this->error($error->getMessage().' Check provider credentials and usage records.');

        return 1;
    } catch (Throwable) {
        $this->error('Fixture synchronization failed. Check provider credentials and usage records.');

        return 1;
    }
})->purpose('Import fixtures and final scores from football-data.org');
// Hourly syncs fetch only nearby matches; a nightly full sync catches any wider schedule changes.
Schedule::command('fixtures:sync', ['--recent'])->hourly()->withoutOverlapping()->when(fn () => config('football.sync_enabled'));
Schedule::command('fixtures:sync')->dailyAt('03:30')->withoutOverlapping()->when(fn () => config('football.sync_enabled'));
Artisan::command('fixtures:backfill', function (FixtureImporter $importer) {
    try {
        $this->info('Imported '.$importer->backfillPreviousSeason().' fixtures from the previous season.');
    } catch (Throwable) {
        $this->error('Backfill failed. Check provider credentials and usage records.');

        return 1;
    }
})->purpose('Import the previous season so the results model has a full year of history');
Artisan::command('providers:prune', function () {
    $count = ProviderCall::where('created_at', '<', now()->subDays(30))->delete();
    $this->info('Removed '.$count.' old provider records.');
})->purpose('Retain 30 days of provider telemetry');
Schedule::command('providers:prune')->daily();
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
Artisan::command('demo:refresh', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Sample refresh is available only in local and testing environments.');

        return 1;
    }
    $this->call('db:seed');
    $league = League::where('name', 'Demo Premier League')->whereNull('source')->firstOrFail();
    // Preserve any fixture that already has prediction history or recorded results.
    $teams = $league->teams()->orderBy('id')->take(4)->get();
    $count = 0;
    for ($i = 0; $i < 4; $i++) {
        $attributes = ['league_id' => $league->id, 'home_team_id' => $teams[$i]->id, 'away_team_id' => $teams[($i + 1) % 4]->id];
        $fixture = Fixture::where($attributes)->whereNull('source')->where('is_finished', false)->doesntHave('predictions')->first();
        if (! $fixture) {
            $fixture = new Fixture($attributes);
        }
        $fixture->fill(['kickoff_at' => now()->addDays($i + 1)->setTime(18, 0), 'status' => 'scheduled', 'is_finished' => false, 'season' => (string) now()->year, 'matchday' => 1])->save();
        $count++;
    }
    $this->info('Refreshed '.$count.' upcoming sample fixtures. Prediction history, results and credit balances were preserved.');
})->purpose('Prepare upcoming sample fixtures without resetting balances or history');
