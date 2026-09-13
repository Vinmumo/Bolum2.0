<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\TrackRecordService;
use Illuminate\Http\Request;

class TrackRecordController extends Controller
{
    public function __invoke(Request $request, Company $company, TrackRecordService $service)
    {
        $data = $request->validate(['league_id' => ['required', 'integer', 'exists:leagues,id'], 'season' => ['required', 'digits:4'],
            'matchday' => ['required', 'integer', 'between:1,100'], 'quality' => ['sometimes', 'in:external,sample,mixed']]);

        return response()->json(['data' => $service->report($company, (int) $data['league_id'], (string) $data['season'], (int) $data['matchday'], $data['quality'] ?? 'external')]);
    }
}
