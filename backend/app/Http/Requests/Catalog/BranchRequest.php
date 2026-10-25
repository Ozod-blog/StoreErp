<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // API route middleware checks branches.* permission.
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name', 'address', 'phone', 'manager_name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $branch = $this->route('branch');
        $id = is_object($branch) ? $branch->getKey() : $branch;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('branches', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:120', Rule::unique('branches', 'name')->ignore($id)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'manager_name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
