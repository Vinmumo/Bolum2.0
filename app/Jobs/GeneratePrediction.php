<?php

namespace App\Jobs;

use App\Enums\PredictionStatus;
use App\Models\Company;
use App\Models\Prediction;
use App\Services\PredictionCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GeneratePrediction implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $companyId, public int $predictionId) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('prediction:'.$this->companyId.':'.$this->predictionId))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(PredictionCalculator $calculator): void
    {
        $prediction = Prediction::where('company_id', $this->companyId)->findOrFail($this->predictionId);
        if ($prediction->status !== PredictionStatus::Pending) {
            return;
        }
        // Network calls run outside database transactions and use immutable request snapshots.
        $result = $calculator->calculate($prediction->fixture_snapshot, $prediction->provider_snapshot);
        DB::transaction(function () use ($result) {
            $prediction = Prediction::where('company_id', $this->companyId)->lockForUpdate()->findOrFail($this->predictionId);
            if ($prediction->status === PredictionStatus::Pending) {
                $prediction->update(['status' => PredictionStatus::Completed, 'result' => $result]);
            }
        }, 3);
    }

    public function failed(?\Throwable $exception): void
    {
        DB::transaction(function () {
            $company = Company::lockForUpdate()->findOrFail($this->companyId);
            $prediction = $company->predictions()->lockForUpdate()->findOrFail($this->predictionId);
            if ($prediction->status !== PredictionStatus::Pending) {
                return;
            }
            $prediction->update(['status' => PredictionStatus::Failed, 'error' => 'Prediction generation failed. Your credit has been refunded.']);
            $company->increment('credits');
            $company->creditEntries()->create(['user_id' => $prediction->user_id, 'prediction_id' => $prediction->id, 'idempotency_key' => 'refund:'.$prediction->id, 'kind' => 'prediction_refund', 'amount' => 1, 'balance_after' => $company->credits]);
        }, 3);
        Log::warning('Prediction job exhausted retries.', ['company_id' => $this->companyId, 'prediction_id' => $this->predictionId, 'exception_type' => $exception ? $exception::class : null]);
    }
}
