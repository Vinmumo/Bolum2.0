<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Fixture;

class TrackRecordService
{
    public function __construct(private ForecastEligibility $eligibility) {}

    public function report(Company $company, int $league, string $season, int $matchday, string $quality): array
    {
        $fixtures = Fixture::with(['homeTeam', 'awayTeam'])->where('league_id', $league)->where('season', $season)->where('matchday', $matchday)->orderBy('kickoff_at')->orderBy('id')->limit(101)->get();
        abort_if($fixtures->count() > 100, 422, 'This gameweek exceeds the report limit.');
        $byId = $fixtures->keyBy('id');
        $forecasts = [];
        $company->predictions()->whereIn('fixture_id', $fixtures->modelKeys())->where('status', 'completed')->whereNotNull('completed_at')
            ->orderByDesc('id')->chunkByIdDesc(200, function ($predictions) use (&$forecasts, $byId, $quality) {
                foreach ($predictions as $prediction) {
                    if (isset($forecasts[$prediction->fixture_id])) {
                        continue;
                    }
                    $probabilities = $this->eligibility->probabilities($prediction, $byId[$prediction->fixture_id], $quality);
                    if ($probabilities !== null) {
                        $forecasts[$prediction->fixture_id] = ['id' => $prediction->id, 'created_at' => $prediction->created_at->toIso8601String(),
                            'pick' => array_keys($probabilities, max($probabilities), true)[0], 'probabilities' => $probabilities,
                            'score' => $prediction->result['most_likely_score'] ?? null];
                    }
                }
            });
        $finished = $evaluated = $correct = 0;
        $rows = $fixtures->map(function ($fixture) use ($forecasts, &$finished, &$evaluated, &$correct) {
            $final = $fixture->is_finished && $fixture->home_goals !== null && $fixture->away_goals !== null;
            $forecast = $forecasts[$fixture->id] ?? null;
            $actual = $final ? ($fixture->home_goals > $fixture->away_goals ? 'home_win' : ($fixture->home_goals === $fixture->away_goals ? 'draw' : 'away_win')) : null;
            $hit = $final && $forecast ? $forecast['pick'] === $actual : null;
            $finished += (int) $final;
            $evaluated += (int) ($hit !== null);
            $correct += (int) ($hit === true);

            return ['fixture_id' => $fixture->id, 'home_team' => $fixture->homeTeam->name, 'away_team' => $fixture->awayTeam->name,
                'kickoff_at' => $fixture->kickoff_at->toIso8601String(), 'status' => $fixture->status,
                'score' => $final ? ['home' => $fixture->home_goals, 'away' => $fixture->away_goals] : null,
                'forecast' => $forecast, 'actual' => $actual, 'correct' => $hit];
        });

        return ['league_id' => $league, 'season' => $season, 'matchday' => $matchday, 'quality' => $quality,
            'fixtures' => $fixtures->count(), 'finished' => $finished, 'evaluated' => $evaluated, 'correct' => $correct,
            'accuracy' => $evaluated ? $correct / $evaluated : null, 'missing_forecasts' => $fixtures->count() - count($forecasts),
            'rows' => $rows, 'method' => 'Latest eligible saved pre-kickoff forecast per fixture in this workspace and data category. Correct means home/draw/away outcome, not exact score.'];
    }
}
