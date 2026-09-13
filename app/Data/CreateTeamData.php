<?php

namespace App\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateTeamData extends Data
{
    public function __construct(
        public int $league_id,
        public string $name,
    ) {}

    public static function rules(ValidationContext $context): array
    {
        return [
            'league_id' => ['required', 'integer', 'exists:leagues,id'],
            'name' => ['required', 'string', 'max:100', Rule::unique('teams')->where('league_id', $context->payload['league_id'] ?? null)],
        ];
    }
}
