<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class RecordFixtureResultData extends Data
{
    public function __construct(
        public int $home_goals,
        public int $away_goals,
    ) {}

    public static function rules(?ValidationContext $context = null): array
    {
        return [
            'home_goals' => ['required', 'integer', 'between:0,100'],
            'away_goals' => ['required', 'integer', 'between:0,100'],
        ];
    }
}
