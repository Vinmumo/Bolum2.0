<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Bolum Football Predictions API',
    'version' => 'v1',
    'fixtures' => url('/api/v1/fixtures'),
    'health' => url('/up'),
    'documentation' => 'https://github.com/Vinmumo/Bolum2.0#readme',
]));
