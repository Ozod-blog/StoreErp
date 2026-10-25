<?php

namespace App\Http\Requests\Cash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->input('code'))]);
        }
    }

    public function rules(): array
    {
        $route = $this->route('cashbox');
        $id = $route instanceof Model ? $route->getKey() : $route;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('cashboxes', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
