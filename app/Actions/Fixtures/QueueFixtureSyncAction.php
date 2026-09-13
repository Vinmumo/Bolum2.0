<?php

namespace App\Actions\Fixtures;

use App\Jobs\SyncFixtures;
use App\Models\FixtureSync;
use Illuminate\Support\Facades\DB;

class QueueFixtureSyncAction
{
    public function execute(): FixtureSync
    {
        abort_unless(config('football.data_token'), 409, 'Configure the football-data.org token on the server first.');

        return DB::transaction(function () {
            $sync = FixtureSync::create(['status' => 'pending']);
            SyncFixtures::dispatch($sync->id)->afterCommit();

            return $sync;
        });
    }
}
