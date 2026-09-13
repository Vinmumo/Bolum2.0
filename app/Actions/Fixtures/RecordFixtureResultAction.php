<?php

namespace App\Actions\Fixtures;

use App\Data\RecordFixtureResultData;
use App\Models\Fixture;
use Illuminate\Support\Facades\DB;

class RecordFixtureResultAction
{
    public function execute(Fixture $fixture, RecordFixtureResultData $data): Fixture
    {
        return DB::transaction(function () use ($data, $fixture) {
            $fixture = Fixture::lockForUpdate()->findOrFail($fixture->id);
            abort_if($fixture->kickoff_at->isFuture(), 409, 'A future fixture cannot have a final result.');
            abort_if(in_array($fixture->status, ['cancelled', 'postponed']), 409, 'This fixture cannot be finalized in its current state.');
            $fixture->update([...$data->attributes(), 'status' => 'finished', 'is_finished' => true, 'result_recorded_at' => now()]);

            return $fixture;
        }, 3);
    }
}
