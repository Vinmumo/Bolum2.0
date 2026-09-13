<?php

namespace App\Data;

final readonly class LoginData
{
    public function __construct(public string $email, public string $password) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self($data['email'], $data['password']);
    }
}
