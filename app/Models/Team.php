<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ['source', 'external_id', 'league_id', 'name', 'crest_url'];

    public static function normalizeCrestUrl(mixed $url): ?string
    {
        // Only the fixture provider's public image CDN is supported; no arbitrary URLs.
        return is_string($url) && strlen($url) <= 2048 && preg_match('~\Ahttps://crests\.football-data\.org/[a-z0-9_-]+\.(?:png|svg|webp)\z~i', $url)
            ? $url : null;
    }

    protected function casts(): array
    {
        return [];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}
