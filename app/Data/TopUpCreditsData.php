<?php

namespace App\Data;

final readonly class TopUpCreditsData
{
    public function __construct(public int $amount, public string $idempotencyKey) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self((int) $data['amount'], $data['idempotency_key']);
    }
}
