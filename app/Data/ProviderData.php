<?php

namespace App\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class ProviderData extends Data
{
    public function __construct(
        public string $name,
        public string $driver,
        public float $weight,
        public Optional|bool $is_active = new Optional,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('providers')->ignore(request()->route('provider'))],
            'driver' => ['required', Rule::in(['sample', 'http', 'results'])],
            'weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
