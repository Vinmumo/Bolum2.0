<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;

class CatalogQueryRequest extends ListRequest
{
    private const FILTERS = ['q', 'league_id', 'matchday', 'season', 'status', 'upcoming', 'name', 'country', 'driver', 'is_active'];

    public function rules(): array
    {
        $rules = [...parent::rules(),
            'name' => ['sometimes', 'string', 'max:100'],
            'country' => ['sometimes', 'string', 'max:100'],
            'driver' => ['sometimes', 'in:sample,http,results'],
            'is_active' => ['sometimes', 'boolean'],
            'filter' => ['sometimes', 'array'],
            'sort' => ['sometimes', 'string', 'max:100'],
            'include' => ['sometimes', 'string', 'max:100'],
        ];

        foreach (self::FILTERS as $filter) {
            $rules['filter.'.$filter] = $rules[$filter];
        }

        return $rules;
    }

    public function forQueryBuilder(): Request
    {
        // Keep existing dashboard URLs valid; explicit filter[...] values take precedence.
        $query = $this->query();
        $query['filter'] = array_replace($this->only(self::FILTERS), $query['filter'] ?? []);

        return $this->duplicate($query);
    }
}
