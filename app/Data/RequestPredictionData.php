<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class RequestPredictionData extends Data
{
    public function __construct(
        public string $idempotency_key,
    ) {}

    public static function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ];
    }
}
