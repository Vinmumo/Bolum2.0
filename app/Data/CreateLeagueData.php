<?php

namespace App\Data;

final readonly class CreateLeagueData
{
    public function __construct(public string $name, public string $country) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self($data['name'], $data['country']);
    }

    public function attributes(): array
    {
        return ['name' => $this->name, 'country' => $this->country];
    }
}
