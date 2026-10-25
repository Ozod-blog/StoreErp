<?php

namespace App\Http\Requests\Cash;

use Illuminate\Foundation\Http\FormRequest;

class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
