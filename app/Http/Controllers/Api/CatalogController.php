<?php

namespace App\Http\Controllers\Api;

use App\Actions\Catalog\CreateCatalogEntryAction;
use App\Actions\Catalog\UpdateProviderAction;
use App\Data\CreateLeagueData;
use App\Data\CreateTeamData;
use App\Data\ProviderData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogQueryRequest;
use App\Http\Resources\CatalogResource;
use App\Models\League;
use App\Models\Provider;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CatalogController extends Controller
{
    private function model(string $catalog): string
    {
        return match ($catalog) {
            'leagues' => League::class,'teams' => Team::class,'providers' => Provider::class
        };
    }

    public function index(CatalogQueryRequest $request)
    {
        $model = $this->model($request->route()->defaults['catalog']);
        $filters = [AllowedFilter::partial('name')->delimiter('')];
        $filters = [...$filters, ...match ($model) {
            League::class => [AllowedFilter::partial('country')->delimiter('')],
            Team::class => [AllowedFilter::exact('league_id')],
            Provider::class => [AllowedFilter::exact('driver'), AllowedFilter::exact('is_active')],
        }];

        $records = QueryBuilder::for($model, $request->forQueryBuilder())
            ->allowedFilters(...$filters)
            ->defaultSort('id')
            ->allowedSorts(...($model === Provider::class ? ['id', 'name', 'weight'] : ['id', 'name']))
            ->allowedIncludes(...($model === Team::class ? ['league'] : []))
            ->paginate($request->integer('per_page', 15))
            ->appends($request->query());

        return CatalogResource::collection($records);
    }

    public function store(Request $request, CreateCatalogEntryAction $action)
    {
        Gate::authorize('manage-catalog');
        $data = match ($request->route()->defaults['catalog']) {
            'leagues' => CreateLeagueData::from($request),
            'teams' => CreateTeamData::from($request),
            'providers' => ProviderData::from($request),
        };

        return new CatalogResource($action->execute($data));
    }

    public function update(Request $request, Provider $provider, UpdateProviderAction $action)
    {
        Gate::authorize('manage-catalog');
        $provider = $action->execute($provider, ProviderData::from($request));

        return new CatalogResource($provider);
    }
}
