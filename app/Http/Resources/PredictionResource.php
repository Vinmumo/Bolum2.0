<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PredictionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'fixture_id' => $this->fixture_id, 'requested_by' => $this->user_id, 'status' => $this->status->value, 'result' => $this->result, 'error' => $this->error, 'providers' => $this->provider_snapshot, 'fixture_snapshot' => $this->fixture_snapshot, 'fixture' => new FixtureResource($this->whenLoaded('fixture')), 'created_at' => $this->created_at->toIso8601String()];
    }
}
