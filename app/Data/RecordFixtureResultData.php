<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class RecordFixtureResultData extends Data
{
    public function __construct(
        public int $home_goals,
        public int $away_goals,
    ) {}

    public static function rules(): array
    {
        return [
            'home_goals' => ['required', 'integer', 'between:0,100'],
            'away_goals' => ['required', 'integer', 'between:0,100'],
        ];
    }
}
