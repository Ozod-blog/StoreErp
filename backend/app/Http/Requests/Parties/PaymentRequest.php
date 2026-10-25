<?php

namespace App\Http\Requests\Parties;

use App\Services\DebtPaymentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Qarz to'lovi: mijozdan (route: customer) yoki yetkazib beruvchiga (route: supplier). */
class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $methods = $this->route('supplier') ? DebtPaymentService::SUPPLIER_METHODS : DebtPaymentService::CUSTOMER_METHODS;

        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999999.99'],
            'method' => ['required', Rule::in($methods)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'client_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
