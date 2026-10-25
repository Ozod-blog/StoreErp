<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:employees,user_id'],
            'employee_code' => ['required', 'string', 'max:40', 'unique:employees,employee_code'],
            'phone' => ['nullable', 'string', 'max:40'],
            'position' => ['required', 'string', 'max:100'],
            'hire_date' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'leave'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
