<?php

namespace Tests\Feature;

use App\Exceptions\ProviderUnavailable;
use App\Models\Fixture;
use App\Models\League;
use App\Models\MarketOdds;
use App\Models\Team;
use App\Services\OddsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketOddsTest extends TestCase
{
    use RefreshDatabase;

    private Fixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['football.odds_api_key' => 'odds-secret', 'football.competitions' => ['PL']]);
        $league = League::create(['source' => 'football-data', 'external_id' => '2021', 'code' => 'PL', 'name' => 'Premier League', 'country' => 'England']);
        $home = Team::create(['league_id' => $league->id, 'name' => 'Brighton & Hove Albion FC']);
        $away = Team::create(['league_id' => $league->id, 'name' => 'Leeds United FC']);
        $this->fixture = Fixture::create(['source' => 'football-data', 'external_id' => '900', 'league_id' => $league->id,
            'home_team_id' => $home->id, 'away_team_id' => $away->id, 'kickoff_at' => now()->addDays(2)->setTime(15, 0)]);
    }

    private function event(string $home, string $away, array $prices, string $id = 'evt-1'): array
    {
        return ['id' => $id, 'sport_key' => 'soccer_epl', 'commence_time' => $this->fixture->kickoff_at->addMinutes(30)->toIso8601String(),
            'home_team' => $home, 'away_team' => $away, 'bookmakers' => array_map(fn ($p) => ['key' => 'b', 'title' => 'B', 'last_update' => now()->subHour()->toIso8601String(),
                'markets' => [['key' => 'h2h', 'outcomes' => [['name' => $home, 'price' => $p[0]], ['name' => 'Draw', 'price' => $p[1]], ['name' => $away, 'price' => $p[2]]]]]], $prices)];
    }

    public function test_club_names_match_across_providers_without_confusing_similar_clubs(): void
    {
        $this->assertTrue(OddsImporter::sameClub('Brighton & Hove Albion FC', 'Brighton and Hove Albion'));
        $this->assertTrue(OddsImporter::sameClub('Arsenal FC', 'Arsenal'));
        $this->assertTrue(OddsImporter::sameClub('Atlético de Madrid', 'Atletico de Madrid'));
        $this->assertFalse(OddsImporter::sameClub('Manchester United FC', 'Manchester City'));
        $this->assertFalse(OddsImporter::sameClub('FC', 'AFC'));
    }

    public function test_consensus_removes_each_bookmakers_margin_and_skips_unmatched_events(): void
    {
        Http::fake(['api.the-odds-api.com/*' => Http::response([
            $this->event('Brighton and Hove Albion', 'Leeds United', [[2.0, 3.5, 4.0], [2.1, 3.4, 3.8]]),
            $this->event('Chelsea', 'Everton', [[1.5, 4.0, 6.0]], 'evt-2'),
        ], 200, ['x-requests-remaining' => '480'])]);
        $result = app(OddsImporter::class)->run();
        $this->assertSame(['saved' => 1, 'unmatched' => 1, 'credits_remaining' => 480], $result);
        $snapshot = MarketOdds::sole();
        $fair = fn ($prices) => array_map(fn ($p) => (1 / $p) / array_sum(array_map(fn ($q) => 1 / $q, $prices)), $prices);
        [$a, $b] = [$fair([2.0, 3.5, 4.0]), $fair([2.1, 3.4, 3.8])];
        $this->assertEqualsWithDelta(($a[0] + $b[0]) / 2, $snapshot->home_win, 1e-6);
        $this->assertEqualsWithDelta(1, $snapshot->home_win + $snapshot->draw + $snapshot->away_win, 1e-5);
        $this->assertSame(2, $snapshot->bookmakers);
        $this->assertGreaterThan(0, $snapshot->average_margin);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sports/soccer_epl/odds') && $r->data()['regions'] === 'uk' && $r->data()['markets'] === 'h2h');
        // The match view shows only odds observed before kickoff.
        $this->getJson('/api/v1/fixtures/'.$this->fixture->id)->assertOk()->assertJsonPath('market.bookmakers', 2);
        MarketOdds::query()->update(['observed_at' => $this->fixture->kickoff_at->addMinute()]);
        $this->getJson('/api/v1/fixtures/'.$this->fixture->id)->assertOk()->assertJsonPath('market', null);
    }

    public function test_sync_stops_before_the_monthly_credits_run_out(): void
    {
        Http::fake();
        Cache::forever(OddsImporter::CREDITS_CACHE_KEY, 10);
        try {
            app(OddsImporter::class)->run();
            $this->fail('Expected the credit guard to stop the sync.');
        } catch (ProviderUnavailable $error) {
            $this->assertStringContainsString('10 left', $error->getMessage());
        }
        Http::assertNothingSent();
        config(['football.odds_api_key' => '']);
        $this->artisan('odds:sync')->assertFailed();
    }

    public function test_leagues_that_are_not_imported_or_supported_cost_no_credits(): void
    {
        Http::fake();
        config(['football.competitions' => ['PD', 'XX1']]);
        $this->assertSame(0, app(OddsImporter::class)->run()['saved']);
        Http::assertNothingSent();
    }
}
