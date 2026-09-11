<?php

namespace App\Services;

use App\Models\Fixture;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class MatchHistory
{
    public function eligible(Fixture $fixture, CarbonImmutable $cutoff): Builder
    {
        return Fixture::query()->where('league_id', $fixture->league_id)->where('source', 'football-data')
            ->where('id', '!=', $fixture->id)->where('status', 'finished')->where('is_finished', true)
            ->whereNotNull('home_goals')->whereNotNull('away_goals')->whereNotNull('result_recorded_at')
            ->where('result_recorded_at', '<=', $cutoff)->where('updated_at', '<=', $cutoff)
            ->where('kickoff_at', '<', $cutoff)->where('kickoff_at', '>=', $cutoff->subDays(365));
    }

    public function form(Fixture $fixture): array
    {
        $cutoff = CarbonImmutable::now()->min($fixture->kickoff_at);
        $result = ['cutoff_at' => $cutoff->toIso8601String(), 'source' => 'football-data', 'order' => 'newest_first'];
        foreach (['home' => $fixture->home_team_id, 'away' => $fixture->away_team_id] as $side => $teamId) {
            $matches = $fixture->source === 'football-data'
                ? $this->eligible($fixture, $cutoff)->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
                    ->with(['homeTeam', 'awayTeam'])->orderByDesc('kickoff_at')->orderByDesc('id')->limit(5)->get()
                : collect();
            $result[$side] = $matches->map(function (Fixture $match) use ($teamId) {
                $home = $match->home_team_id === $teamId;
                $for = $home ? $match->home_goals : $match->away_goals;
                $against = $home ? $match->away_goals : $match->home_goals;

                return ['fixture_id' => $match->id, 'kickoff_at' => $match->kickoff_at->toIso8601String(), 'venue' => $home ? 'home' : 'away',
                    'opponent' => ($home ? $match->awayTeam : $match->homeTeam)->name,
                    'goals_for' => $for, 'goals_against' => $against, 'outcome' => $for > $against ? 'W' : ($for === $against ? 'D' : 'L')];
            })->all();
        }

        return $result;
    }
}
