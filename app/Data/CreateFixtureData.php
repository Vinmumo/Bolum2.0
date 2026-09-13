<?php

namespace App\Data;

final readonly class CreateFixtureData
{
    public function __construct(public int $leagueId, public int $homeTeamId, public int $awayTeamId, public string $kickoffAt, public ?bool $isFinished = null) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self((int) $data['league_id'], (int) $data['home_team_id'], (int) $data['away_team_id'], $data['kickoff_at'], isset($data['is_finished']) ? (bool) $data['is_finished'] : null);
    }

    public function attributes(): array
    {
        return array_filter(['league_id' => $this->leagueId, 'home_team_id' => $this->homeTeamId, 'away_team_id' => $this->awayTeamId, 'kickoff_at' => $this->kickoffAt, 'is_finished' => $this->isFinished], fn ($value) => $value !== null);
    }
}
