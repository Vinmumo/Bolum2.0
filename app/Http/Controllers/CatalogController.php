<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Http\Requests\ListRequest;
use App\Http\Resources\CatalogResource;
use App\Models\League;
use App\Models\Provider;
use App\Models\Team;

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

        return CatalogResource::collection($model::query()->orderBy('id')->paginate($request->integer('per_page', 15)));
    }

    public function store(CatalogRequest $request)
    {
        $model = $this->model($request->route()->defaults['catalog']);

        return new CatalogResource($model::create($request->validated()));
    }

    public function update(CatalogRequest $request, Provider $provider)
    {
        $provider->update($request->validated());

        return new CatalogResource($provider);
    }
}
