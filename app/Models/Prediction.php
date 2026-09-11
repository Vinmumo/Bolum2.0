<?php

namespace App\Models;

use App\Enums\PredictionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    protected $fillable = ['company_id', 'fixture_id', 'user_id', 'idempotency_key', 'status', 'provider_snapshot', 'fixture_snapshot', 'result', 'error', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime', 'status' => PredictionStatus::class, 'provider_snapshot' => 'array', 'fixture_snapshot' => 'array', 'result' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
