<?php

namespace App\Actions\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class RevokeApiTokenAction
{
    public function execute(User $user): void
    {
        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 409, 'Use the session logout endpoint for browser sessions.');
        $token->delete();
    }
}
