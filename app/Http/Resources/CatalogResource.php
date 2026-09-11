<?php

namespace App\Http\Resources;

use App\Models\League;
use App\Models\Provider;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, ...match (true) {
            $this->resource instanceof League => ['country' => $this->country, 'source' => $this->source ?? 'local'],$this->resource instanceof Team => ['league_id' => $this->league_id, 'crest_url' => Team::normalizeCrestUrl($this->crest_url)],$this->resource instanceof Provider => ['driver' => $this->driver, 'weight' => $this->weight, 'is_active' => $this->is_active],default => []
        }];
    }
}
