<?php

namespace App\Http\Requests\Cash;

use Illuminate\Foundation\Http\FormRequest;

/** Smenani yopish va topshirish uchun (topshirishda to_user_id majburiy). */
class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actual_cash' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999'],
            'reason' => ['nullable', 'string', 'max:255'],
            'to_user_id' => [str_ends_with($this->path(), '/handover') ? 'required' : 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
