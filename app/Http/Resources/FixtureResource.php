<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FixtureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'league' => new CatalogResource($this->whenLoaded('league')), 'home_team' => new CatalogResource($this->whenLoaded('homeTeam')), 'away_team' => new CatalogResource($this->whenLoaded('awayTeam')), 'kickoff_at' => $this->kickoff_at->toIso8601String(), 'is_finished' => $this->is_finished];
    }
}
