<?php

namespace App\Http\Controllers;

use App\Http\Requests\FixtureRequest;
use App\Http\Requests\ListRequest;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class FixtureController extends Controller
{
    private const RELATIONS = ['league', 'homeTeam', 'awayTeam'];

    public function index(ListRequest $request)
    {
        return FixtureResource::collection(Fixture::with(self::RELATIONS)->when($request->filled('league_id'), fn ($q) => $q->where('league_id', $request->integer('league_id')))->when($request->boolean('upcoming'), fn ($q) => $q->upcoming())->orderBy('id')->paginate($request->integer('per_page', 15)));
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
        if (Schema::hasTable('predictions')) {
            abort_if(DB::table('predictions')->where('fixture_id', $fixture->id)->exists(), 409, 'Fixtures with prediction history cannot be deleted.');
        } $fixture->delete();

        return response()->noContent();
    }
}
