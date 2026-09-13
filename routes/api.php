<?php

use App\Http\Controllers\Api\AdminOverviewController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClubProfileController;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\FixtureController;
use App\Http\Controllers\Api\FixtureOptionsController;
use App\Http\Controllers\Api\FixtureResultController;
use App\Http\Controllers\Api\FixtureSyncController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProviderUsageController;
use App\Http\Controllers\Api\StandingsController;
use App\Http\Controllers\Api\TrackRecordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('teams/{team}/profile', ClubProfileController::class)->middleware('throttle:football-read');
    Route::get('profile', [ProfileController::class, 'show'])->middleware('auth:sanctum');
    Route::patch('profile', [ProfileController::class, 'update'])->middleware(['auth:sanctum', 'throttle:account']);
    Route::put('profile/password', [ProfileController::class, 'password'])->middleware(['auth:sanctum', 'throttle:account']);
    Route::get('admin/overview', AdminOverviewController::class)->middleware(['auth:sanctum', 'can:manage-catalog']);
    Route::get('leagues/{league}/standings', StandingsController::class)->middleware('throttle:football-read');
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
    Route::get('fixtures/filter-options', FixtureOptionsController::class);
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

Route::get('v1/providers/usage', ProviderUsageController::class)->middleware(['auth:sanctum', 'can:manage-catalog']);

Route::post('v1/fixtures/sync', FixtureSyncController::class)->middleware(['auth:sanctum', 'can:manage-catalog', 'throttle:predictions']);
Route::put('v1/fixtures/{fixture}/result', FixtureResultController::class)->middleware(['auth:sanctum', 'can:manage-catalog']);
Route::get('v1/companies/{company}/performance', PerformanceController::class)->middleware(['auth:sanctum', 'company.member']);

Route::get('v1/companies/{company}/track-record', TrackRecordController::class)->middleware(['auth:sanctum', 'company.member', 'can:manage-catalog']);
