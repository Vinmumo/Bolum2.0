<?php

namespace App\Services;

use App\Enums\PredictionStatus;
use App\Exceptions\ProviderUnavailable;
use App\Jobs\GeneratePrediction;
use App\Models\Company;
use App\Models\Fixture;
use App\Models\Prediction;
use App\Models\Provider;
use App\Models\User;
use App\Services\Providers\ResultsFootballProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PredictionService
{
    public function __construct(private ResultsFootballProvider $results) {}

    public function request(Company $company, Fixture $fixture, User $user, string $key): Prediction
    {
        return DB::transaction(function () use ($company, $fixture, $user, $key) {
            // Serialize requests per company; the unique constraint is the final safeguard.
            $company = Company::lockForUpdate()->findOrFail($company->id);
            if ($existing = $company->predictions()->where('idempotency_key', $key)->first()) {
                abort_if($existing->fixture_id !== $fixture->id || $existing->user_id !== $user->id, 409, 'Idempotency key was used for a different request.');

                return $existing;
            }
            $fixture = Fixture::with(['homeTeam', 'awayTeam'])->findOrFail($fixture->id);
            abort_if($fixture->status !== 'scheduled' || $fixture->is_finished || $fixture->kickoff_at->isPast(), 409, 'Predictions require an upcoming fixture.');
            abort_if($company->credits < 1, 409, 'Insufficient demo credits.');
            $providers = Provider::where('is_active', true)->orderBy('id')->get(['id', 'name', 'driver', 'weight'])->toArray();
            abort_if(! $providers, 409, 'No active providers.');
            $snapshot = [...$fixture->only(['id', 'league_id', 'home_team_id', 'away_team_id', 'kickoff_at', 'source', 'season']), 'home_name' => $fixture->homeTeam->name, 'away_name' => $fixture->awayTeam->name];
            if (collect($providers)->contains('driver', 'results')) {
                try {
                    $snapshot['results_inputs'] = $this->results->snapshot($fixture, CarbonImmutable::now());
                } catch (ProviderUnavailable $error) {
                    abort(409, $error->getMessage());
                }
            }
            $prediction = $company->predictions()->create(['status' => PredictionStatus::Pending, 'fixture_id' => $fixture->id, 'user_id' => $user->id, 'idempotency_key' => $key, 'provider_snapshot' => $providers, 'fixture_snapshot' => $snapshot]);
            $company->decrement('credits');
            $company->creditEntries()->create(['user_id' => $user->id, 'prediction_id' => $prediction->id, 'idempotency_key' => 'debit:'.$prediction->id, 'kind' => 'prediction_debit', 'amount' => -1, 'balance_after' => $company->credits]);
            GeneratePrediction::dispatch($company->id, $prediction->id)->afterCommit();

            return $prediction;
        }, 3);
    }
}
