<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Provider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $admin = User::firstOrCreate(['email' => 'admin@bolum.test'], ['name' => 'Demo Admin', 'password' => 'password123']);
        $admin->forceFill(['is_admin' => true])->save();
        $member = User::firstOrCreate(['email' => 'member@bolum.test'], ['name' => 'Demo Member', 'password' => 'password123']);
        $company = Company::firstOrCreate(['name' => 'Bolum Demo']);
        $company->users()->syncWithoutDetaching([$admin->id => ['role' => 'owner'], $member->id => ['role' => 'member']]);
        $other = Company::firstOrCreate(['name' => 'Rival Analytics']);
        $league = League::firstOrCreate(['name' => 'Demo Premier League'], ['country' => 'England']);
        $teams = [];
        foreach (['North London', 'Manchester Blue', 'Merseyside Red', 'West London'] as $name) {
            $teams[] = Team::firstOrCreate(['league_id' => $league->id, 'name' => $name]);
        }
        foreach ([[0, 1], [2, 3], [0, 2], [1, 3]] as $i => [$h,$a]) {
            Fixture::firstOrCreate(['league_id' => $league->id, 'home_team_id' => $teams[$h]->id, 'away_team_id' => $teams[$a]->id], ['kickoff_at' => now()->addDays($i + 1)->setTime(18, 0)]);
        }
        Provider::firstOrCreate(['name' => 'Sample Football'], ['driver' => 'sample', 'weight' => 1, 'is_active' => true]);
    }
}
