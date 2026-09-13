<?php

namespace App\Actions\Profile;

use App\Data\UpdateProfileData;
use App\Models\User;

class UpdateProfileAction
{
    public function execute(User $user, UpdateProfileData $data): User
    {
        $user->forceFill($data->attributes())->save();

        return $user;
    }
}
