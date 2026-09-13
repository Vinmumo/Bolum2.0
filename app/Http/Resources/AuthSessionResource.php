<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthSessionResource extends JsonResource
{
    public function __construct(User $user, private ?string $token = null)
    {
        parent::__construct($user);
    }

    public function toArray(Request $request): array
    {
        return ['user' => new UserResource($this->resource),
            'companies' => $this->whenLoaded('companies', fn () => $this->companies->map(fn ($company) => [
                ...$company->only(['id', 'name']), 'pivot' => $company->pivot->only(['user_id', 'company_id', 'role']),
            ])),
            'token' => $this->when($this->token !== null, $this->token)];
    }
}
