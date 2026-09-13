<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProviderUnavailable;
use App\Http\Controllers\Controller;
use App\Models\League;
use App\Services\StandingsService;
use Illuminate\Contracts\Cache\LockTimeoutException;

class StandingsController extends Controller
{
    public function __invoke(League $league, StandingsService $service)
    {
        abort_unless($league->source === 'football-data', 404, 'Standings are available for imported leagues.');
        try {
            return response()->json(['data' => $service->report($league)]);
        } catch (ProviderUnavailable|LockTimeoutException) {
            return response()->json(['message' => 'League standings are temporarily unavailable. Please try again shortly.'], 503);
        }
    }
}
