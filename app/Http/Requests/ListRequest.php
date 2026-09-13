<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['matchday' => ['sometimes', 'integer', 'between:1,100'], 'q' => ['sometimes', 'string', 'max:100'], 'status' => ['sometimes', 'in:scheduled,live,finished,postponed,cancelled'], 'season' => ['sometimes', 'digits:4'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'], 'league_id' => ['sometimes', 'integer', 'exists:leagues,id'], 'upcoming' => ['sometimes', 'boolean']];
    }
}
