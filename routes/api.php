<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\FixtureController;
use App\Http\Controllers\PredictionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });
    foreach (['leagues', 'teams', 'providers'] as $catalog) {
        Route::get($catalog, [CatalogController::class, 'index'])->defaults('catalog', $catalog)->middleware($catalog === 'providers' ? ['auth:sanctum', 'can:manage-catalog'] : []);
        Route::post($catalog, [CatalogController::class, 'store'])->defaults('catalog', $catalog)->middleware('auth:sanctum');
    }
    Route::put('providers/{provider}', [CatalogController::class, 'update'])->defaults('catalog', 'providers')->middleware('auth:sanctum');
    Route::apiResource('fixtures', FixtureController::class)->only(['index', 'show']);
    Route::apiResource('fixtures', FixtureController::class)->only(['store', 'update', 'destroy'])->middleware('auth:sanctum');
});

Route::prefix('v1/companies/{company}')->middleware(['auth:sanctum', 'company.member'])->group(function () {
    Route::get('credits', [CreditController::class, 'index']);
    Route::post('credits/top-ups', [CreditController::class, 'store']);
    Route::get('predictions', [PredictionController::class, 'index']);
    Route::get('predictions/{prediction}', [PredictionController::class, 'show'])->scopeBindings();
    // Fixtures are a shared public catalog and are deliberately not company-owned bindings.
    Route::post('fixtures/{fixture}/predictions', [PredictionController::class, 'store'])->middleware('throttle:predictions');
    Route::get('fixtures/{fixture}/predictions', [PredictionController::class, 'history']);
    Route::get('fixtures/{fixture}/predictions/latest', [PredictionController::class, 'latest']);
});
