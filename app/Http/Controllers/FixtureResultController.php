<?php

namespace App\Http\Controllers;

use App\Http\Requests\FixtureResultRequest;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use Illuminate\Support\Facades\DB;

class FixtureResultController extends Controller
{
    public function __invoke(FixtureResultRequest $request, Fixture $fixture)
    {
        $fixture = DB::transaction(function () use ($request, $fixture) {
            $fixture = Fixture::lockForUpdate()->findOrFail($fixture->id);
            abort_if($fixture->kickoff_at->isFuture(), 409, 'A future fixture cannot have a final result.');
            abort_if(in_array($fixture->status, ['cancelled', 'postponed']), 409, 'This fixture cannot be finalized in its current state.');
            $fixture->update([...$request->validated(), 'status' => 'finished', 'is_finished' => true, 'result_recorded_at' => now()]);

            return $fixture;
        }, 3);

        return new FixtureResource($fixture->load(['league', 'homeTeam', 'awayTeam']));
    }
}
