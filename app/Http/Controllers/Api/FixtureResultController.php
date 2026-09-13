<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fixtures\RecordFixtureResultAction;
use App\Data\RecordFixtureResultData;
use App\Http\Controllers\Controller;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FixtureResultController extends Controller
{
    public function __invoke(Request $request, Fixture $fixture, RecordFixtureResultAction $action)
    {
        Gate::authorize('manage-catalog');
        $fixture = $action->execute($fixture, RecordFixtureResultData::from($request));

        return new FixtureResource($fixture->load(['league', 'homeTeam', 'awayTeam']));
    }
}
