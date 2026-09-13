<?php

namespace App\Http\Controllers\Api;

use App\Actions\Catalog\CreateCatalogEntryAction;
use App\Actions\Catalog\UpdateProviderAction;
use App\Data\CreateLeagueData;
use App\Data\CreateTeamData;
use App\Data\ProviderData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRequest;
use App\Http\Resources\CatalogResource;
use App\Models\League;
use App\Models\Provider;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CatalogController extends Controller
{
    private function model(string $catalog): string
    {
        return match ($catalog) {
            'leagues' => League::class,'teams' => Team::class,'providers' => Provider::class
        };
    }

    public function index(ListRequest $request)
    {
        $model = $this->model($request->route()->defaults['catalog']);

        return CatalogResource::collection($model::query()->when($model === Team::class && $request->filled('league_id'), fn ($q) => $q->where('league_id', $request->integer('league_id')))->orderBy('id')->paginate($request->integer('per_page', 15)));
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
