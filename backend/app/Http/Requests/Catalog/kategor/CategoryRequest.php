<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $route = $this->route('category');
        $id = $route instanceof Model ? $route->getKey() : $route;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('categories', 'name')->ignore($id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
