<?php

namespace App\Http\Controllers;

use App\Http\Requests\FixtureRequest;
use App\Http\Requests\ListRequest;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use Illuminate\Support\Facades\Gate;

class FixtureController extends Controller
{
    private const RELATIONS = ['league', 'homeTeam', 'awayTeam'];

    public function index(ListRequest $request)
    {
        return FixtureResource::collection(Fixture::with(self::RELATIONS)->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->whereHas('homeTeam', fn ($q) => $q->where('name', 'like', '%'.$request->input('q').'%'))->orWhereHas('awayTeam', fn ($q) => $q->where('name', 'like', '%'.$request->input('q').'%'))))->when($request->filled('status'), fn ($q) => $request->input('status') === 'finished' ? $q->where('is_finished', true) : $q->where('status', $request->input('status'))->where('is_finished', false))->when($request->filled('season'), fn ($q) => $q->where('season', $request->input('season')))->when($request->filled('league_id'), fn ($q) => $q->where('league_id', $request->integer('league_id')))->when($request->boolean('upcoming'), fn ($q) => $q->upcoming())->orderBy('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Fixture $fixture)
    {
        return new FixtureResource($fixture->load(self::RELATIONS));
    }

    public function store(FixtureRequest $request)
    {
        return new FixtureResource(Fixture::create($request->validated())->load(self::RELATIONS));
    }

    public function update(FixtureRequest $request, Fixture $fixture)
    {
        $fixture->update($request->validated());

        return new FixtureResource($fixture->load(self::RELATIONS));
    }

    public function destroy(Fixture $fixture)
    {
        Gate::authorize('manage-catalog');
        abort_if($fixture->predictions()->exists(), 409, 'Fixtures with prediction history cannot be deleted.');
        $fixture->delete();

        return response()->noContent();
    }
}
