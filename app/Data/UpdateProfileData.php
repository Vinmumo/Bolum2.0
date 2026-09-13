<?php

namespace App\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class UpdateProfileData extends Data
{
    public function __construct(
        public string $name,
        public string $avatar,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'avatar' => ['required', Rule::in(['football', 'captain', 'keeper', 'trophy', 'stadium', 'lightning'])],
        ];
    }
}
