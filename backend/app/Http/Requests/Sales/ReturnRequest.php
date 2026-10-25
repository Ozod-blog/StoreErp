<?php

namespace App\Http\Requests\Sales;

use App\Services\SaleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReturnRequest extends FormRequest
{
    /** Ruxsat route middleware (can:sales.update) orqali tekshiriladi. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_token' => ['nullable', 'string', 'max:64'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
            'refund_method' => ['nullable', Rule::in(SaleService::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999.999', 'decimal:0,3'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Qaytarish sababini kiriting.',
            'note.min' => 'Qaytarish sababini kiriting.',
            'items.required' => 'Qaytariladigan mahsulotni tanlang.',
            'items.min' => 'Qaytariladigan mahsulotni tanlang.',
            'items.*.quantity.gt' => 'Miqdor 0 dan katta bo‘lishi kerak.',
        ];
    }
}
