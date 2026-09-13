<?php

namespace App\Data;

use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Data;

class ChangePasswordData extends Data
{
    public function __construct(
        public string $current_password,
        public string $password,
    ) {}

    public static function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(10)],
        ];
    }
}
