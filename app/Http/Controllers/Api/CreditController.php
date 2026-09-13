<?php

namespace App\Http\Controllers\Api;

use App\Actions\Credits\TopUpCreditsAction;
use App\Data\TopUpCreditsData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRequest;
use App\Http\Resources\CreditEntryResource;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CreditController extends Controller
{
    public function index(ListRequest $request, Company $company)
    {
        return CreditEntryResource::collection($company->creditEntries()->latest('id')->paginate($request->integer('per_page', 15)))->additional(['balance' => $company->credits, 'unit' => 'demo_credit']);
    }

    public function store(Request $request, Company $company, TopUpCreditsAction $action)
    {
        Gate::authorize('topUp', $company);
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        return new CreditEntryResource($action->execute($company, $request->user(), TopUpCreditsData::from($request)));
    }
}
