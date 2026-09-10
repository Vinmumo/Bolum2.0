<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fixture extends Model
{
    use HasFactory;

    protected $fillable = ['league_id', 'home_team_id', 'away_team_id', 'kickoff_at', 'is_finished'];

    protected function casts(): array
    {
        return ['kickoff_at' => 'immutable_datetime', 'is_finished' => 'boolean'];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('kickoff_at', '>', now())->where('is_finished', false)->orderBy('kickoff_at');
    }
}
