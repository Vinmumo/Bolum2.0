<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class CreateLeagueData extends Data
{
    public function __construct(
        public string $name,
        public string $country,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:leagues,name'],
            'country' => ['required', 'string', 'max:100'],
        ];
    }
}
