<?php

namespace App\Data;

use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class RegisterUserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $company_name,
    ) {}

    public static function rules(?ValidationContext $context = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'company_name' => ['required', 'string', 'max:100'],
        ];
    }
}
