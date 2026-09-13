<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fixture;
use App\Models\FixtureSync;
use App\Models\Prediction;
use App\Models\Provider;
use App\Models\ProviderCall;

class AdminOverviewController extends Controller
{
    public function __invoke()
    {
        return response()->json(['data' => [
            'active_providers' => Provider::where('is_active', true)->count(),
            'pending_predictions' => Prediction::where('status', 'pending')->count(),
            'stale_predictions' => Prediction::where('status', 'pending')->where('created_at', '<', now()->subMinutes(5))->count(),
            'failed_predictions_24h' => Prediction::where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
            'provider_errors_24h' => ProviderCall::where('created_at', '>=', now()->subDay())->whereNotIn('status', ['success', 'cached'])->count(),
            'imported_fixtures' => Fixture::where('source', 'football-data')->count(),
            'last_completed_sync' => FixtureSync::where('status', 'completed')->latest('updated_at')->value('updated_at'),
            'fixtures_updated_at' => Fixture::where('source', 'football-data')->max('updated_at'),
        ]]);
    }
}
