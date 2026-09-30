<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketOdds extends Model
{
    protected $table = 'market_odds';

    protected $fillable = ['fixture_id', 'source', 'external_id', 'bookmakers', 'home_win', 'draw', 'away_win', 'average_margin', 'observed_at', 'bookmaker_updated_at'];

    protected function casts(): array
    {
        return ['bookmakers' => 'integer', 'home_win' => 'float', 'draw' => 'float', 'away_win' => 'float', 'average_margin' => 'float',
            'observed_at' => 'immutable_datetime', 'bookmaker_updated_at' => 'immutable_datetime'];
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return array{home_win: float, draw: float, away_win: float} */
    public function probabilities(): array
    {
        return ['home_win' => $this->home_win, 'draw' => $this->draw, 'away_win' => $this->away_win];
    }

    public function toSummary(): array
    {
        return ['source' => $this->source, 'probabilities' => $this->probabilities(), 'bookmakers' => $this->bookmakers,
            'average_margin' => $this->average_margin, 'observed_at' => $this->observed_at->toIso8601String()];
    }
}
