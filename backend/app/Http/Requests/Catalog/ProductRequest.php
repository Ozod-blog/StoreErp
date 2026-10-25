<?php

namespace App\Http\Requests\Catalog;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Eslatma: bu yerda qoldiq (quantity) maydoni YO'Q — qoldiq faqat ombor hujjatlari orqali o'zgaradi.
 * Tahrirlashda ham to'liq maydonlar yuboriladi (PUT/PATCH).
 */
class ProductRequest extends FormRequest
{
    private const MONEY_MAX = '999999999999.99';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['sku', 'barcode', 'name'] as $key) {
            if (is_string($this->input($key))) {
                $merge[$key] = trim($this->input($key));
            }
        }
        // Bo'sh shtrix-kod NULL bo'lishi kerak (unique cheklov bo'sh qatorlarni to'qnashtirmasligi uchun).
        if (array_key_exists('barcode', $merge) && $merge['barcode'] === '') {
            $merge['barcode'] = null;
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $route = $this->route('product');
        $id = $route instanceof Model ? $route->getKey() : $route;

        return [
            'sku' => ['required', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($id)],
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:'.self::MONEY_MAX],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:'.self::MONEY_MAX],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:'.self::MONEY_MAX],
            'min_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $min = $this->input('min_stock');
                if ($min === null || $min === '' || ! is_numeric($min) || ! $this->input('unit_id')) {
                    return;
                }
                $unit = Unit::find($this->input('unit_id'));
                if ($unit && ! $unit->allow_fraction && floor((float) $min) !== (float) $min) {
                    $validator->errors()->add('min_stock', "“{$unit->name}” birligida kasrli miqdor kiritib bo‘lmaydi.");
                }
            },
        ];
    }
}
