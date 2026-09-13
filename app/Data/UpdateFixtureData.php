<?php

namespace App\Data;

final readonly class UpdateFixtureData
{
    public function __construct(public ?int $leagueId = null, public ?int $homeTeamId = null, public ?int $awayTeamId = null, public ?string $kickoffAt = null, public ?bool $isFinished = null) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self(isset($data['league_id']) ? (int) $data['league_id'] : null, isset($data['home_team_id']) ? (int) $data['home_team_id'] : null, isset($data['away_team_id']) ? (int) $data['away_team_id'] : null, isset($data['kickoff_at']) ? $data['kickoff_at'] : null, isset($data['is_finished']) ? (bool) $data['is_finished'] : null);
    }

    public function attributes(): array
    {
        return array_filter(['league_id' => $this->leagueId, 'home_team_id' => $this->homeTeamId, 'away_team_id' => $this->awayTeamId, 'kickoff_at' => $this->kickoffAt, 'is_finished' => $this->isFinished], fn ($value) => $value !== null);
    }
}
