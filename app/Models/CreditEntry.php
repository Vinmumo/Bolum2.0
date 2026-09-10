<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditEntry extends Model
{
    protected $fillable = ['company_id', 'user_id', 'prediction_id', 'idempotency_key', 'kind', 'amount', 'balance_after'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }
}
