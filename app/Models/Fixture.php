<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fixture extends Model
{
    use HasFactory;

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    protected $attributes = ['status' => 'scheduled', 'is_finished' => false];

    protected $fillable = ['source', 'external_id', 'league_id', 'home_team_id', 'away_team_id', 'kickoff_at', 'is_finished', 'season', 'matchday', 'status', 'home_goals', 'away_goals', 'result_recorded_at'];

    protected function casts(): array
    {
        return ['home_goals' => 'integer', 'away_goals' => 'integer', 'result_recorded_at' => 'immutable_datetime', 'kickoff_at' => 'immutable_datetime', 'is_finished' => 'boolean'];
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
        return $query->where('kickoff_at', '>', now())->where('is_finished', false)->where('status', 'scheduled')->orderBy('kickoff_at');
    }
}
