<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\PerformanceService;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    public function __invoke(Request $request, Company $company, PerformanceService $service)
    {
        $request->validate(['quality' => ['sometimes', 'in:external,mixed,sample']]);

        return response()->json(['data' => $service->report($company, $request->input('quality', 'external'))]);
    }
}
