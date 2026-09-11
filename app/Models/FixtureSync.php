<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixtureSync extends Model
{
    protected $fillable = ['status', 'imported', 'error'];
}
