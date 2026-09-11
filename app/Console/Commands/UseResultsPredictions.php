<?php

namespace App\Console\Commands;

use App\Exceptions\ProviderUnavailable;
use App\Models\Fixture;
use App\Models\Provider;
use App\Services\Providers\ResultsFootballProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UseResultsPredictions extends Command
{
    protected $signature = 'predictions:use-results';

    protected $description = 'Enable the match-results model and pause synthetic providers';

    public function handle(ResultsFootballProvider $results): int
    {
        $fixture = Fixture::upcoming()->where('source', 'football-data')->orderBy('kickoff_at')->first();
        if (! $fixture) {
            $this->error('Sync upcoming real fixtures before enabling match-results predictions.');

            return self::FAILURE;
        }
        try {
            $inputs = $results->snapshot($fixture, CarbonImmutable::now());
        } catch (ProviderUnavailable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        DB::transaction(function () {
            $provider = Provider::where('driver', 'results')->orderBy('id')->first()
                ?? new Provider(['name' => 'Match Results Model', 'driver' => 'results', 'weight' => 1]);
            $provider->is_active = true;
            $provider->save();
            Provider::where('driver', 'sample')->update(['is_active' => false]);
        });
        $this->info('Match-results predictions enabled. Synthetic providers paused. Existing HTTP providers preserved.');
        $this->line('Preflight: '.$inputs['league_matches'].' recorded league matches. Each request checks its own teams before charging.');
        if ($inputs['limited_sample']) {
            $this->warn('Venue samples are small; estimates are smoothed toward league averages.');
        }

        return self::SUCCESS;
    }
}
