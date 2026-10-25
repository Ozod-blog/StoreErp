<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockDocumentRequest extends FormRequest
{
    /** Ruxsat route middleware (can:purchases.* / can:warehouses.*) orqali tekshiriladi. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return list<string> */
    private function allowedTypes(): array
    {
        return $this->is('api/v1/purchases*') ? ['purchase', 'purchase_return'] : ['issue', 'transfer', 'inventory'];
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999999'];

        return [
            'type' => ['required', Rule::in($this->allowedTypes())],
            'warehouse_id' => [Rule::requiredIf($type !== 'purchase_return'), 'nullable', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => [Rule::requiredIf($type === 'transfer'), 'nullable', 'integer', 'exists:warehouses,id', 'different:warehouse_id'],
            'supplier_id' => [Rule::requiredIf($type === 'purchase'), 'nullable', 'integer', 'exists:suppliers,id'],
            'parent_id' => [Rule::requiredIf($type === 'purchase_return'), 'nullable', 'integer', 'exists:stock_documents,id'],
            'doc_date' => ['required', 'date_format:Y-m-d'],
            'discount' => $money,
            'paid_amount' => $money,
            'payment_method' => ['nullable', Rule::in(['cash', 'bank'])],
            'reason' => [Rule::requiredIf($type === 'issue'), 'nullable', Rule::in(['write_off', 'damage', 'own_use', 'other'])],
            'note' => ['nullable', 'string', 'max:500'],
            'client_token' => ['nullable', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', $type === 'inventory' ? 'min:0' : 'gt:0', 'max:999999999'],
            'items.*.price' => [Rule::requiredIf($type === 'purchase'), 'nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.received_at' => ['nullable', 'date'],
            'items.*.expires_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Hujjatga kamida bitta mahsulot qo‘shing.',
            'items.min' => 'Hujjatga kamida bitta mahsulot qo‘shing.',
            'items.*.product_id.distinct' => 'Bir mahsulot hujjatda faqat bir marta bo‘lishi mumkin.',
            'items.*.quantity.gt' => 'Miqdor 0 dan katta bo‘lishi kerak.',
            'to_warehouse_id.different' => 'Qabul qiluvchi ombor boshqa bo‘lishi kerak.',
        ];
    }
}
