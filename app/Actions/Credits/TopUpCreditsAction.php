<?php

namespace App\Actions\Credits;

use App\Data\TopUpCreditsData;
use App\Models\Company;
use App\Models\CreditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TopUpCreditsAction
{
    public function execute(Company $company, User $user, TopUpCreditsData $data): CreditEntry
    {
        $amount = $data->amount;
        $key = $data->idempotencyKey;

        return DB::transaction(function () use ($company, $user, $amount, $key) {
            $company = Company::lockForUpdate()->findOrFail($company->id);
            $key = 'topup:'.$key;
            if ($entry = $company->creditEntries()->where('idempotency_key', $key)->first()) {
                abort_if($entry->amount !== $amount || $entry->user_id !== $user->id, 409, 'Idempotency key was used for a different request.');

                return $entry;
            }
            abort_if($company->credits + $amount > 1000000, 409, 'Demo credit balance limit exceeded.');
            $company->increment('credits', $amount);

            return $company->creditEntries()->create(['user_id' => $user->id, 'idempotency_key' => $key, 'kind' => 'demo_topup', 'amount' => $amount, 'balance_after' => $company->credits]);
        }, 3);
    }
}
