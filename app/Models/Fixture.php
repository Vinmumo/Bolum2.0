<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Fixture extends Model
{
    use HasFactory;

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function marketOdds(): HasMany
    {
        return $this->hasMany(MarketOdds::class);
    }

    /** Most recent bookmaker consensus observed before kickoff, i.e. what a pre-match forecast could be compared with. */
    public function latestPreKickoffOdds(): ?MarketOdds
    {
        return $this->marketOdds()->where('observed_at', '<', $this->kickoff_at)->latest('observed_at')->latest('id')->first();
    }

    public const FILTER_OPTIONS_CACHE_KEY = 'fixtures:filter-options';

    protected static function booted(): void
    {
        // Admin edits refresh the cached round list; the importer clears it after its bulk writes.
        $forget = fn () => Cache::forget(self::FILTER_OPTIONS_CACHE_KEY);
        static::saved($forget);
        static::deleted($forget);
    }

    protected $attributes = ['status' => 'scheduled', 'is_finished' => false];

    protected $fillable = ['source', 'external_id', 'league_id', 'home_team_id', 'away_team_id', 'kickoff_at', 'is_finished', 'season', 'matchday', 'status', 'home_goals', 'away_goals', 'result_recorded_at'];

    protected function casts(): array
    {
        return ['home_goals' => 'integer', 'away_goals' => 'integer', 'result_recorded_at' => 'immutable_datetime', 'kickoff_at' => 'immutable_datetime', 'is_finished' => 'boolean'];
    }

    /** Store kickoffs in the app timezone; Eloquent otherwise keeps an input's offset and drops it. */
    protected function kickoffAt(): Attribute
    {
        return Attribute::set(fn ($value) => $value === null ? null
            : $this->fromDateTime(CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))));
    }

    /** Imported fixtures are owned by the provider feed; the next sync would overwrite local edits. */
    public function isImported(): bool
    {
        return $this->source !== null;
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
