<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockDocument extends Model
{
    public const PREFIXES = [
        'purchase' => 'XR',
        'purchase_return' => 'QY',
        'issue' => 'CH',
        'transfer' => 'KO',
        'inventory' => 'IN',
        'opening' => 'BQ',
    ];

    protected $fillable = [
        'number', 'type', 'status', 'warehouse_id', 'to_warehouse_id', 'supplier_id', 'parent_id', 'doc_date',
        'discount', 'total', 'paid_amount', 'payment_method', 'reason', 'note', 'cancel_reason', 'client_token',
        'created_by', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'doc_date' => 'date:Y-m-d',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StockDocumentItem::class)->orderBy('id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
