<?php

namespace App\Http\Requests;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FixtureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalog') ?? false;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return ['league_id' => [$required, 'integer', 'exists:leagues,id'], 'home_team_id' => [$required, 'integer', 'exists:teams,id'], 'away_team_id' => [$required, 'integer', 'exists:teams,id'], 'kickoff_at' => [$required, 'date'], 'is_finished' => ['sometimes', 'boolean']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $fixture = $this->route('fixture');
            $league = $this->input('league_id', $fixture?->league_id);
            $home = $this->input('home_team_id', $fixture?->home_team_id);
            $away = $this->input('away_team_id', $fixture?->away_team_id);
            if ($home == $away) {
                $validator->errors()->add('away_team_id', 'Home and away teams must differ.');
            }
            foreach (['home_team_id' => $home, 'away_team_id' => $away] as $key => $id) {
                if (! Team::whereKey($id)->where('league_id', $league)->exists()) {
                    $validator->errors()->add($key, 'The team must belong to the selected league.');
                }
            }
        }];
    }
}
