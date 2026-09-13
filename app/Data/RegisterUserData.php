<?php

namespace App\Data;

use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Data;

class RegisterUserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $company_name,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'company_name' => ['required', 'string', 'max:100'],
        ];
    }
}
