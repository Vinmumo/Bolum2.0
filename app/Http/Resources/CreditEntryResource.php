<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'amount' => $this->amount, 'balance_after' => $this->balance_after, 'prediction_id' => $this->prediction_id, 'user_id' => $this->user_id, 'created_at' => $this->created_at->toIso8601String()];
    }
}
