<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['warehouse_id', 'product_id', 'stock_document_id', 'sale_id', 'type', 'quantity', 'balance_after', 'unit_cost', 'user_id'];

    protected $casts = ['quantity' => 'decimal:3', 'balance_after' => 'decimal:3', 'unit_cost' => 'decimal:4'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function document()
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
