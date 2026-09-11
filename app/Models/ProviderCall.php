<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderCall extends Model
{
    protected $fillable = ['source', 'operation', 'status', 'http_status', 'duration_ms', 'cache_hit', 'attempt'];

    protected function casts(): array
    {
        return ['cache_hit' => 'boolean', 'duration_ms' => 'integer'];
    }
}
