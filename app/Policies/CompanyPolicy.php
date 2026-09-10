<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function topUp(User $user, Company $company): bool
    {
        return $company->users()->whereKey($user->id)->wherePivot('role', 'owner')->exists();
    }

    public function generate(User $user, Company $company): bool
    {
        return $company->users()->whereKey($user->id)->exists();
    }
}
