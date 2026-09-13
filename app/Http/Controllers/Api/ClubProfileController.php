<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProviderUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\ClubProfileService;
use Illuminate\Contracts\Cache\LockTimeoutException;

class ClubProfileController extends Controller
{
    public function __invoke(Team $team, ClubProfileService $service)
    {
        abort_unless($team->source === 'football-data', 404, 'Club profiles are available for imported teams.');
        try {
            $report = $service->report($team);
        } catch (ProviderUnavailable|LockTimeoutException) {
            return response()->json(['message' => 'Club information is temporarily unavailable. Please try again shortly.'], 503);
        }
        abort_unless($report['profile'], 404, 'No matching club profile was found.');

        return response()->json(['data' => [...$report['profile'], 'team_id' => $team->id,
            'source' => 'TheSportsDB', 'source_url' => 'https://www.thesportsdb.com/team/'.$report['profile']['external_id'],
            'fetched_at' => $report['fetched_at']]]);
    }
}
