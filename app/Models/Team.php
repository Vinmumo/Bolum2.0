<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ['source', 'external_id', 'league_id', 'name'];

    protected function casts(): array
    {
        return [];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}
