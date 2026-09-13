<?php

namespace App\Data;

final readonly class UpdateProfileData
{
    public function __construct(public string $name, public string $avatar) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self($data['name'], $data['avatar']);
    }

    public function attributes(): array
    {
        return ['name' => $this->name, 'avatar' => $this->avatar];
    }
}
