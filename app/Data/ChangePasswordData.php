<?php

namespace App\Data;

final readonly class ChangePasswordData
{
    public function __construct(public string $currentPassword, public string $password) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self($data['current_password'], $data['password']);
    }
}
