<?php

namespace App\Http\Controllers\Api;

use App\Actions\Predictions\RequestPredictionAction;
use App\Data\RequestPredictionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRequest;
use App\Http\Resources\PredictionResource;
use App\Models\Company;
use App\Models\Fixture;
use App\Models\Prediction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PredictionController extends Controller
{
    public function store(Request $request, Company $company, Fixture $fixture, RequestPredictionAction $action)
    {
        Gate::authorize('generate', $company);
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        $prediction = $action->execute($company, $fixture, $request->user(), RequestPredictionData::from($request));

        return (new PredictionResource($prediction))->response()->setStatusCode($prediction->wasRecentlyCreated ? 202 : 200);
    }

    public function index(ListRequest $request, Company $company)
    {
        return PredictionResource::collection($company->predictions()->with(['fixture.league', 'fixture.homeTeam', 'fixture.awayTeam'])->latest('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Company $company, Prediction $prediction)
    {
        Gate::authorize('view', $prediction);

        return new PredictionResource($prediction->load(['fixture.league', 'fixture.homeTeam', 'fixture.awayTeam']));
    }

    public function history(ListRequest $request, Company $company, Fixture $fixture)
    {
        return PredictionResource::collection($company->predictions()->where('fixture_id', $fixture->id)->latest('id')->paginate($request->integer('per_page', 15)));
    }

    public function latest(Company $company, Fixture $fixture)
    {
        return new PredictionResource($company->predictions()->where('fixture_id', $fixture->id)->where('status', 'completed')->latest('id')->firstOrFail());
    }
}
