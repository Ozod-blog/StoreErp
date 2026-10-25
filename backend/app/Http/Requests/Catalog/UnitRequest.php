<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Yaratish (POST) va tahrirlash (PUT/PATCH) uchun umumiy validatsiya. */
class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $route = $this->route('unit');
        $id = $route instanceof Model ? $route->getKey() : $route;

        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('units', 'name')->ignore($id)],
            'short_name' => ['required', 'string', 'max:20', Rule::unique('units', 'short_name')->ignore($id)],
            'allow_fraction' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
