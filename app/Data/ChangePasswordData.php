<?php

namespace App\Data;

use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class ChangePasswordData extends Data
{
    public function __construct(
        public string $current_password,
        public string $password,
    ) {}

    public static function rules(?ValidationContext $context = null): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(10)],
        ];
    }
}
