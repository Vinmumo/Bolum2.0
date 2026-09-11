<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FixtureResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-catalog');
    }

    public function rules(): array
    {
        return ['home_goals' => ['required', 'integer', 'between:0,100'], 'away_goals' => ['required', 'integer', 'between:0,100']];
    }
}
