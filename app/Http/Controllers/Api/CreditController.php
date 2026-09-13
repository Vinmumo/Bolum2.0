<?php

namespace App\Http\Controllers\Api;

use App\Actions\Credits\TopUpCreditsAction;
use App\Data\TopUpCreditsData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRequest;
use App\Http\Requests\TopUpRequest;
use App\Http\Resources\CreditEntryResource;
use App\Models\Company;

class CreditController extends Controller
{
    public function index(ListRequest $request, Company $company)
    {
        return CreditEntryResource::collection($company->creditEntries()->latest('id')->paginate($request->integer('per_page', 15)))->additional(['balance' => $company->credits, 'unit' => 'demo_credit']);
    }

    public function store(TopUpRequest $request, Company $company, TopUpCreditsAction $action)
    {
        return new CreditEntryResource($action->execute($company, $request->user(), TopUpCreditsData::fromValidated($request->validated())));
    }
}
