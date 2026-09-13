<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fixtures\RecordFixtureResultAction;
use App\Data\RecordFixtureResultData;
use App\Http\Controllers\Controller;
use App\Http\Requests\FixtureResultRequest;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;

class FixtureResultController extends Controller
{
    public function __invoke(FixtureResultRequest $request, Fixture $fixture, RecordFixtureResultAction $action)
    {
        $fixture = $action->execute($fixture, RecordFixtureResultData::fromValidated($request->validated()));

        return new FixtureResource($fixture->load(['league', 'homeTeam', 'awayTeam']));
    }
}
