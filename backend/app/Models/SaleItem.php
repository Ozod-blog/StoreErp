<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $fillable = ['sale_id', 'product_id', 'quantity', 'price', 'discount', 'discount_share', 'net_total', 'unit_cost'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'discount_share' => 'decimal:2',
        'net_total' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    /** Arxivlangan mahsulot ham tarixiy chekda ko'rinishi uchun withTrashed. */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
