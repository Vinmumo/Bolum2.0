<?php

namespace Database\Factories;

use App\Models\League;
use Illuminate\Database\Eloquent\Factories\Factory;

class TeamFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->city().' FC', 'league_id' => League::factory()];
    }
}
