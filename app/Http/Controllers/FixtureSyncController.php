<?php

namespace App\Http\Controllers;

use App\Jobs\SyncFixtures;
use App\Models\FixtureSync;

class FixtureSyncController extends Controller
{
    public function __invoke()
    {
        abort_unless(config('football.data_token'), 409, 'Configure the football-data.org token on the server first.');
        $sync = FixtureSync::create(['status' => 'pending']);
        SyncFixtures::dispatch($sync->id)->afterCommit();

        return response()->json(['data' => $sync], 202);
    }
}
