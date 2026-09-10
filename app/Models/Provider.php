<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'driver', 'weight', 'is_active'];

    protected function casts(): array
    {
        return ['weight' => 'decimal:3', 'is_active' => 'boolean'];
    }
}
