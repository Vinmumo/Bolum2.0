<?php

namespace App\Data;

final readonly class RequestPredictionData
{
    public function __construct(public string $idempotencyKey) {}

    /** Build only from input already accepted by a Form Request. */
    public static function fromValidated(array $data): self
    {
        return new self($data['idempotency_key']);
    }
}
