<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class RequestPredictionData extends Data
{
    public function __construct(
        public string $idempotency_key,
    ) {}

    public static function rules(?ValidationContext $context = null): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ];
    }
}
