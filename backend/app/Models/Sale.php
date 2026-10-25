<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    public const PREFIXES = ['sale' => 'SV', 'return' => 'SQ'];

    protected $fillable = [
        'number', 'type', 'status', 'parent_id', 'branch_id', 'warehouse_id', 'cashbox_id', 'shift_id', 'customer_id',
        'subtotal', 'discount', 'total', 'paid_amount', 'debt_amount', 'cogs', 'pricing_type', 'loyalty_points_redeemed', 'loyalty_points_earned', 'refund_method', 'note', 'cancel_reason',
        'client_token', 'created_by', 'sold_at', 'due_at', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'debt_amount' => 'decimal:2',
        'cogs' => 'decimal:2',
        'sold_at' => 'datetime',
        'due_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(SaleItem::class)->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashbox()
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function shift()
    {
        return $this->belongsTo(CashboxShift::class, 'shift_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Shu sotuvga bog'langan qaytarishlar. */
    public function returns()
    {
        return $this->hasMany(self::class, 'parent_id')->where('type', 'return');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
