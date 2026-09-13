<?php

namespace App\Actions\Profile;

use App\Data\ChangePasswordData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChangePasswordAction
{
    public function execute(User $user, ChangePasswordData $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            if (! Hash::check($data->currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
            }
            $user->forceFill(['password' => $data->password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });
    }
}
