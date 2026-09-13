<?php

namespace App\Actions\Auth;

use App\Data\LoginData;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticateUserAction
{
    public function execute(LoginData $data): User
    {
        $user = User::where('email', $data->email)->first();
        if (! $user || ! Hash::check($data->password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are incorrect.']]);
        }

        return $user;
    }
}
