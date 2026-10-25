<?php

namespace App\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'employee_code' => ['sometimes', 'required', 'string', 'max:40', Rule::unique('employees', 'employee_code')->ignore($employee?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'position' => ['sometimes', 'required', 'string', 'max:100'],
            'hire_date' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'leave'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
