<?php

namespace App\Http\Controllers;

use App\Models\Fixture;

class FixtureOptionsController extends Controller
{
    public function __invoke()
    {
        $rounds = Fixture::whereNotNull('season')->whereNotNull('matchday')
            ->select(['league_id', 'season', 'matchday'])->selectRaw('COUNT(*) as fixtures')
            ->selectRaw('SUM(CASE WHEN is_finished = ? AND home_goals IS NOT NULL AND away_goals IS NOT NULL THEN 1 ELSE 0 END) as finished', [true])
            ->groupBy(['league_id', 'season', 'matchday'])->orderByDesc('season')->orderBy('matchday')->limit(2000)->get();

        return response()->json(['data' => $rounds->map(fn ($r) => ['league_id' => $r->league_id, 'season' => (string) $r->season, 'matchday' => (int) $r->matchday, 'fixtures' => (int) $r->fixtures, 'finished' => (int) $r->finished])]);
    }
}
