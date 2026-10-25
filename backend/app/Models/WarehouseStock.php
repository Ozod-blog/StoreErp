<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseStock extends Model
{
    protected $fillable = ['warehouse_id', 'product_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Arxivlangan mahsulotning qoldig'i ham ko'rinib turishi uchun withTrashed. */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
