<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRequest;
use App\Http\Requests\TopUpRequest;
use App\Http\Resources\CreditEntryResource;
use App\Models\Company;
use App\Services\CreditService;

class CreditController extends Controller
{
    public function index(ListRequest $request, Company $company)
    {
        return CreditEntryResource::collection($company->creditEntries()->latest('id')->paginate($request->integer('per_page', 15)))->additional(['balance' => $company->credits, 'unit' => 'demo_credit']);
    }

    public function store(TopUpRequest $request, Company $company, CreditService $credits)
    {
        return new CreditEntryResource($credits->topUp($company, $request->user(), $request->integer('amount'), $request->validated('idempotency_key')));
    }
}
