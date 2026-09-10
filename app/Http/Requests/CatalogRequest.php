<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-catalog') ?? false;
    }

    public function rules(): array
    {
        return match ($this->route()->defaults['catalog']) {
            'leagues' => ['name' => ['required', 'string', 'max:100', 'unique:leagues,name'], 'country' => ['required', 'string', 'max:100']],
            'teams' => ['league_id' => ['required', 'integer', 'exists:leagues,id'], 'name' => ['required', 'string', 'max:100', Rule::unique('teams')->where('league_id', $this->input('league_id'))]],
            'providers' => ['name' => ['required', 'string', 'max:100', Rule::unique('providers')->ignore($this->route('provider'))], 'driver' => ['required', Rule::in(['sample', 'http'])], 'weight' => ['required', 'numeric', 'gt:0', 'max:100'], 'is_active' => ['sometimes', 'boolean']],
        };
    }
}
