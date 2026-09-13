<?php

namespace App\Actions\Auth;

use App\Actions\Credits\TopUpCreditsAction;
use App\Data\RegisterUserData;
use App\Data\TopUpCreditsData;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterUserAction
{
    public function __construct(private TopUpCreditsAction $topUp) {}

    public function execute(RegisterUserData $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data->name, 'email' => $data->email, 'password' => $data->password]);
            $company = Company::create(['name' => $data->companyName]);
            $user->companies()->attach($company, ['role' => 'owner']);
            $this->topUp->execute($company, $user, new TopUpCreditsData(10, 'welcome'));

            return $user;
        });
    }
}
