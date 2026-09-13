<?php

namespace App\Data\Concerns;

use App\Models\Fixture;
use App\Models\Team;
use Illuminate\Validation\Validator;

trait ValidatesFixtureTeams
{
    public static function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fixture = request()->route('fixture');
            $fixture = $fixture instanceof Fixture ? $fixture : null;
            $input = $validator->getData();
            $league = $input['league_id'] ?? $fixture?->league_id;
            $home = $input['home_team_id'] ?? $fixture?->home_team_id;
            $away = $input['away_team_id'] ?? $fixture?->away_team_id;

            if ($home == $away) {
                $validator->errors()->add('away_team_id', 'Home and away teams must differ.');
            }

            foreach (['home_team_id' => $home, 'away_team_id' => $away] as $key => $id) {
                if (! Team::whereKey($id)->where('league_id', $league)->exists()) {
                    $validator->errors()->add($key, 'The team must belong to the selected league.');
                }
            }
        });
    }
}
