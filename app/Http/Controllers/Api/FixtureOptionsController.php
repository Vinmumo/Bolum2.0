<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fixture;
use Illuminate\Support\Facades\Cache;

class FixtureOptionsController extends Controller
{
    public function __invoke()
    {
        $rounds = Cache::remember(Fixture::FILTER_OPTIONS_CACHE_KEY, 600, fn () => Fixture::whereNotNull('season')->whereNotNull('matchday')
            ->select(['league_id', 'season', 'matchday'])->selectRaw('COUNT(*) as fixtures')
            ->selectRaw('SUM(CASE WHEN is_finished = ? AND home_goals IS NOT NULL AND away_goals IS NOT NULL THEN 1 ELSE 0 END) as finished', [true])
            ->groupBy(['league_id', 'season', 'matchday'])->orderByDesc('season')->orderBy('matchday')->limit(2000)->get()
            ->map(fn ($r) => ['league_id' => $r->league_id, 'season' => (string) $r->season, 'matchday' => (int) $r->matchday, 'fixtures' => (int) $r->fixtures, 'finished' => (int) $r->finished])->all());

        return response()->json(['data' => $rounds]);
    }
}
