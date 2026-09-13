<?php

namespace App\Data;

final readonly class RecordFixtureResultData
{
    public function __construct(public int $homeGoals, public int $awayGoals) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self((int) $data['home_goals'], (int) $data['away_goals']);
    }

    public function attributes(): array
    {
        return ['home_goals' => $this->homeGoals, 'away_goals' => $this->awayGoals];
    }
}
