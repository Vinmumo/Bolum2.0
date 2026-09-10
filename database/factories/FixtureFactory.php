<?php

namespace Database\Factories;

use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class FixtureFactory extends Factory
{
    public function definition(): array
    {
        return ['league_id' => League::factory(), 'home_team_id' => fn (array $a) => Team::factory()->create(['league_id' => $a['league_id']])->id, 'away_team_id' => fn (array $a) => Team::factory()->create(['league_id' => $a['league_id']])->id, 'kickoff_at' => now()->addDays(3), 'is_finished' => false];
    }
}
