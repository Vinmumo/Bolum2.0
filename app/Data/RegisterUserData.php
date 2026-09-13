<?php

namespace App\Data;

final readonly class RegisterUserData
{
    public function __construct(public string $name, public string $email, public string $password, public string $companyName) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self($data['name'], $data['email'], $data['password'], $data['company_name']);
    }
}
