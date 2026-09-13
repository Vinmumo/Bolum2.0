<?php

namespace App\Data;

use App\Data\Concerns\ValidatesFixtureTeams;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class CreateFixtureData extends Data
{
    use ValidatesFixtureTeams;

    public function __construct(
        public int $league_id,
        public int $home_team_id,
        public int $away_team_id,
        public string $kickoff_at,
        public Optional|bool $is_finished = new Optional,
    ) {}

    public static function rules(): array
    {
        return [
            'league_id' => ['required', 'integer', 'exists:leagues,id'],
            'home_team_id' => ['required', 'integer', 'exists:teams,id'],
            'away_team_id' => ['required', 'integer', 'exists:teams,id'],
            'kickoff_at' => ['required', 'date'],
            'is_finished' => ['sometimes', 'boolean'],
        ];
    }
}
