<?php

namespace App\Services\Providers;

use App\Contracts\FootballDataProvider;
use App\Exceptions\ProviderUnavailable;
use App\Models\Fixture;
use App\Services\MatchHistory;
use Carbon\CarbonImmutable;

class ResultsFootballProvider implements FootballDataProvider
{
    public const VERSION = 'results-rates-v1';

    public function __construct(private MatchHistory $history) {}

    public function snapshot(Fixture $fixture, CarbonImmutable $cutoff): array
    {
        if ($fixture->source !== 'football-data' || $cutoff >= $fixture->kickoff_at) {
            throw new ProviderUnavailable('The match-results model requires an upcoming imported fixture.');
        }
        $matches = $this->history->eligible($fixture, $cutoff)->orderByDesc('kickoff_at')->orderByDesc('id')->limit(1000)->get();
        $homeMatches = $matches->filter(fn ($m) => $m->home_team_id === $fixture->home_team_id || $m->away_team_id === $fixture->home_team_id);
        $awayMatches = $matches->filter(fn ($m) => $m->home_team_id === $fixture->away_team_id || $m->away_team_id === $fixture->away_team_id);
        if ($matches->count() < 20 || $homeMatches->count() < 3 || $awayMatches->count() < 3) {
            throw new ProviderUnavailable('Not enough recorded results: this model needs 20 league matches and 3 matches per team within the last year.');
        }
        $weight = fn ($match) => 0.5 ** (abs($cutoff->diffInSeconds($match->kickoff_at)) / 86400 / 90);
        $leagueWeight = $matches->sum($weight);
        $leagueHome = max(0.15, $matches->sum(fn ($m) => $m->home_goals * $weight($m)) / $leagueWeight);
        $leagueAway = max(0.15, $matches->sum(fn ($m) => $m->away_goals * $weight($m)) / $leagueWeight);
        $homeVenue = $homeMatches->where('home_team_id', $fixture->home_team_id);
        $awayVenue = $awayMatches->where('away_team_id', $fixture->away_team_id);
        // Five equivalent league-average matches stabilize small venue samples.
        $rate = fn ($rows, $field, $prior) => ($rows->sum(fn ($m) => $m->$field * $weight($m)) + 5 * $prior) / ($rows->sum($weight) + 5);
        $homeAttack = $rate($homeVenue, 'home_goals', $leagueHome);
        $homeDefence = $rate($homeVenue, 'away_goals', $leagueAway);
        $awayAttack = $rate($awayVenue, 'away_goals', $leagueAway);
        $awayDefence = $rate($awayVenue, 'home_goals', $leagueHome);
        $cap = fn ($goals) => max(0.15, min(5.0, $goals));

        return ['model_version' => self::VERSION, 'source' => 'football-data', 'cutoff_at' => $cutoff->toIso8601String(),
            'home' => $cap($homeAttack * $awayDefence / $leagueHome), 'away' => $cap($awayAttack * $homeDefence / $leagueAway),
            'league_matches' => $matches->count(), 'home_matches' => $homeMatches->count(), 'away_matches' => $awayMatches->count(),
            'home_venue_matches' => $homeVenue->count(), 'away_venue_matches' => $awayVenue->count(),
            'limited_sample' => $homeVenue->count() < 5 || $awayVenue->count() < 5,
            'league_home_rate' => $leagueHome, 'league_away_rate' => $leagueAway,
            'home_attack_rate' => $homeAttack, 'home_defence_rate' => $homeDefence, 'away_attack_rate' => $awayAttack, 'away_defence_rate' => $awayDefence,
            'parameters' => ['lookback_days' => 365, 'half_life_days' => 90, 'prior_matches' => 5, 'minimum_expected_goals' => 0.15, 'maximum_expected_goals' => 5],
            'latest_match_at' => $matches->first()->kickoff_at->toIso8601String(),
            'oldest_match_at' => $matches->last()->kickoff_at->toIso8601String(), 'fixture_ids' => $matches->pluck('id')->all()];
    }

    public function expectedGoals(array $fixture): array
    {
        $snapshot = $fixture['results_inputs'] ?? null;
        if (! is_array($snapshot) || ($snapshot['model_version'] ?? null) !== self::VERSION
            || ! is_numeric($snapshot['home'] ?? null) || ! is_numeric($snapshot['away'] ?? null)) {
            throw new ProviderUnavailable('Frozen match-results inputs are unavailable.');
        }

        // The worker uses inputs frozen when the request was accepted, never today's DB state.
        return ['home' => (float) $snapshot['home'], 'away' => (float) $snapshot['away']];
    }
}
