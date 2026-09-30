<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fixtures\CreateFixtureAction;
use App\Actions\Fixtures\DeleteFixtureAction;
use App\Actions\Fixtures\UpdateFixtureAction;
use App\Data\CreateFixtureData;
use App\Data\UpdateFixtureData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogQueryRequest;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use App\Services\MatchHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class FixtureController extends Controller
{
    private const RELATIONS = ['league', 'homeTeam', 'awayTeam'];

    public function index(CatalogQueryRequest $request)
    {
        $fixtures = QueryBuilder::for(Fixture::with(self::RELATIONS), $request->forQueryBuilder())
            ->allowedFilters(...[
                AllowedFilter::exact('league_id'),
                AllowedFilter::exact('matchday'),
                AllowedFilter::exact('season'),
                AllowedFilter::callback('q', fn ($query, $value) => $query->where(fn ($teams) => $teams
                    ->whereHas('homeTeam', fn ($team) => $team->where('name', 'like', '%'.$value.'%'))
                    ->orWhereHas('awayTeam', fn ($team) => $team->where('name', 'like', '%'.$value.'%'))))->delimiter(''),
                AllowedFilter::callback('status', fn ($query, $value) => $value === 'finished'
                    ? $query->where('is_finished', true)
                    : $query->where('status', $value)->where('is_finished', false)),
                AllowedFilter::callback('upcoming', fn ($query, $value) => $query->when(filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    fn ($query) => $query->where('kickoff_at', '>', now())->where('is_finished', false)->where('status', 'scheduled'))),
            ])
            ->defaultSort('id')
            ->allowedSorts(...['id', 'kickoff_at', 'matchday'])
            ->allowedIncludes(...self::RELATIONS)
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());

        return FixtureResource::collection($fixtures);
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
