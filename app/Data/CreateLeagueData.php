<?php

namespace App\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateLeagueData extends Data
{
    public function __construct(
        public string $name,
        public string $country,
    ) {}

    public static function rules(?ValidationContext $context = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('leagues', 'name')->whereNull('source')],
            'country' => ['required', 'string', 'max:100'],
        ];
    }
}
