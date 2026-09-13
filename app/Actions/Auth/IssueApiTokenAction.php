<?php

namespace App\Actions\Auth;

use App\Models\User;

class IssueApiTokenAction
{
    public function execute(User $user): string
    {
        return $user->createToken('api', ['*'], now()->addHours(8))->plainTextToken;
    }
}
