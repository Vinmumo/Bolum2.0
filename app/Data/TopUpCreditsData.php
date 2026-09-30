<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class TopUpCreditsData extends Data
{
    public function __construct(
        public int $amount,
        public string $idempotency_key,
    ) {}

    public static function rules(?ValidationContext $context = null): array
    {
        return [
            'amount' => ['required', 'integer', 'between:1,10000'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ];
    }
}
