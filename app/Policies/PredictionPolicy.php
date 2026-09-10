<?php

namespace App\Policies;

use App\Models\Prediction;
use App\Models\User;

class PredictionPolicy
{
    public function view(User $user, Prediction $prediction): bool
    {
        return $user->companies()->whereKey($prediction->company_id)->exists();
    }
}
