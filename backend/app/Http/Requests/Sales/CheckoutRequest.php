<?php

namespace App\Http\Requests\Sales;

use App\Services\SaleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    /** Ruxsat route middleware (can:sales.create) orqali; chegirma/qarz ruxsatlari SaleService ichida tekshiriladi. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_token' => ['nullable', 'string', 'max:64'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'pricing_type' => ['nullable', Rule::in(['retail', 'wholesale'])],
            'loyalty_card_code' => ['nullable', 'string', 'max:80'],
            'loyalty_points_redeem' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999.999', 'decimal:0,3'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'payments' => ['nullable', 'array', 'max:5'],
            'payments.*.method' => ['required', Rule::in(SaleService::METHODS), 'distinct'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,2'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Savatga kamida bitta mahsulot qo‘shing.',
            'items.min' => 'Savatga kamida bitta mahsulot qo‘shing.',
            'items.*.product_id.distinct' => 'Bir mahsulot savatda faqat bir marta bo‘lishi mumkin.',
            'items.*.quantity.gt' => 'Miqdor 0 dan katta bo‘lishi kerak.',
            'items.*.quantity.max' => 'Miqdor 99 999.999 dan oshmasligi kerak.',
            'items.*.price.max' => 'Narx 99 999 999.99 dan oshmasligi kerak.',
            'payments.*.method.distinct' => 'Bir to‘lov usuli bir marta kiritiladi.',
            'payments.*.amount.gt' => 'To‘lov summasi 0 dan katta bo‘lishi kerak.',
        ];
    }
}
