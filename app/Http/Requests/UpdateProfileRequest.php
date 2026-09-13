<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'avatar' => ['required', Rule::in(['football', 'captain', 'keeper', 'trophy', 'stadium', 'lightning'])]];
    }
}
