<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fixtures\CreateFixtureAction;
use App\Actions\Fixtures\DeleteFixtureAction;
use App\Actions\Fixtures\UpdateFixtureAction;
use App\Data\CreateFixtureData;
use App\Data\UpdateFixtureData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRequest;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use App\Services\MatchHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FixtureController extends Controller
{
    private const RELATIONS = ['league', 'homeTeam', 'awayTeam'];

    public function index(ListRequest $request)
    {
        return FixtureResource::collection(Fixture::with(self::RELATIONS)->when($request->filled('matchday'), fn ($q) => $q->where('matchday', $request->integer('matchday')))->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->whereHas('homeTeam', fn ($q) => $q->where('name', 'like', '%'.$request->input('q').'%'))->orWhereHas('awayTeam', fn ($q) => $q->where('name', 'like', '%'.$request->input('q').'%'))))->when($request->filled('status'), fn ($q) => $request->input('status') === 'finished' ? $q->where('is_finished', true) : $q->where('status', $request->input('status'))->where('is_finished', false))->when($request->filled('season'), fn ($q) => $q->where('season', $request->input('season')))->when($request->filled('league_id'), fn ($q) => $q->where('league_id', $request->integer('league_id')))->when($request->boolean('upcoming'), fn ($q) => $q->upcoming())->orderBy('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Fixture $fixture, MatchHistory $history)
    {
        return (new FixtureResource($fixture->load(self::RELATIONS)))->additional(['form' => $history->form($fixture)]);
    }

    public function store(Request $request, CreateFixtureAction $action)
    {
        Gate::authorize('manage-catalog');

        return new FixtureResource($action->execute(CreateFixtureData::from($request))->load(self::RELATIONS));
    }

    public function update(Request $request, Fixture $fixture, UpdateFixtureAction $action)
    {
        Gate::authorize('manage-catalog');
        $fixture = $action->execute($fixture, UpdateFixtureData::from($request));

        return new FixtureResource($fixture->load(self::RELATIONS));
    }

    public function destroy(Fixture $fixture, DeleteFixtureAction $action)
    {
        Gate::authorize('manage-catalog');
        $action->execute($fixture);

        return response()->noContent();
    }
}
