<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class TopUpCreditsData extends Data
{
    public function __construct(
        public int $amount,
        public string $idempotency_key,
    ) {}

    public static function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'between:1,10000'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ];
    }
}
