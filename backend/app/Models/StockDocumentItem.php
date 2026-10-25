<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockDocumentItem extends Model
{
    protected $fillable = ['stock_document_id', 'product_id', 'quantity', 'price', 'unit_cost', 'system_qty', 'diff_qty', 'batch_number', 'received_at', 'expires_at'];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'system_qty' => 'decimal:3',
        'diff_qty' => 'decimal:3',
        'received_at' => 'date',
        'expires_at' => 'date',
    ];

    public function document()
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
