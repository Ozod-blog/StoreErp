<?php

namespace App\Http\Requests\Cash;

use App\Models\WorkSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bitta kun (upsert) yoki ommaviy (dates[]) belgilash. */
class WorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => [Rule::requiredIf(! $this->has('dates')), 'nullable', 'date_format:Y-m-d'],
            'dates' => ['nullable', 'array', 'min:1', 'max:62'],
            'dates.*' => ['date_format:Y-m-d', 'distinct'],
            'type' => ['required', Rule::in(WorkSchedule::TYPES)],
            'start_time' => ['nullable', 'date_format:H:i', 'required_if:type,work'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_if:type,work', 'after:start_time'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
