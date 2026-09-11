<?php

use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard');
Route::post('/session/login', [SessionController::class, 'store'])->middleware('throttle:auth');
Route::post('/session/logout', [SessionController::class, 'destroy'])->middleware('auth');

Route::post('/session/register', [SessionController::class, 'register'])->middleware('throttle:auth');
