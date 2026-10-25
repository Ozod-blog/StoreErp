<?php

namespace App\Http\Requests\Cash;

use Illuminate\Foundation\Http\FormRequest;

class CashTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'direction' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999'],
            'note' => ['required', 'string', 'min:3', 'max:255'],
            'client_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
