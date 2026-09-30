<?php

namespace App\Data;

use App\Data\Concerns\ValidatesFixtureTeams;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class UpdateFixtureData extends Data
{
    use ValidatesFixtureTeams;

    public function __construct(
        public Optional|int $league_id = new Optional,
        public Optional|int $home_team_id = new Optional,
        public Optional|int $away_team_id = new Optional,
        public Optional|string $kickoff_at = new Optional,
        public Optional|bool $is_finished = new Optional,
    ) {}

    public static function rules(?ValidationContext $context = null): array
    {
        return [
            'league_id' => ['sometimes', 'integer', 'exists:leagues,id'],
            'home_team_id' => ['sometimes', 'integer', 'exists:teams,id'],
            'away_team_id' => ['sometimes', 'integer', 'exists:teams,id'],
            'kickoff_at' => ['sometimes', 'date'],
            'is_finished' => ['sometimes', 'boolean'],
        ];
    }
}
