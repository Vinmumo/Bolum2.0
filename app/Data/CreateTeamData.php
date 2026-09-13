<?php

namespace App\Data;

final readonly class CreateTeamData
{
    public function __construct(public int $leagueId, public string $name) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self((int) $data['league_id'], $data['name']);
    }

    public function attributes(): array
    {
        return ['league_id' => $this->leagueId, 'name' => $this->name];
    }
}
