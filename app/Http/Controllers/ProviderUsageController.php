<?php

namespace App\Http\Controllers;

use App\Models\FixtureSync;
use App\Models\ProviderCall;
use Illuminate\Http\Request;

class ProviderUsageController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate(['days' => ['sometimes', 'integer', 'between:1,30']]);
        $calls = ProviderCall::where('created_at', '>=', now()->subDays($request->integer('days', 1)));
        $summary = (clone $calls)->select('source')->selectRaw('COUNT(*) as calls, SUM(cache_hit) as cache_hits, AVG(duration_ms) as average_ms')->selectRaw("SUM(CASE WHEN status NOT IN ('success','cached') THEN 1 ELSE 0 END) as failures")->groupBy('source')->get();

        return response()->json(['data' => $summary, 'recent' => (clone $calls)->latest('id')->limit(30)->get(['id', 'source', 'operation', 'status', 'http_status', 'duration_ms', 'cache_hit', 'created_at']), 'syncs' => FixtureSync::latest('id')->limit(5)->get()]);
    }
}
