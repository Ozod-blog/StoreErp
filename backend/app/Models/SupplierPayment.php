<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    protected $fillable = [
        'number', 'supplier_id', 'amount', 'method', 'reference', 'note', 'status', 'shift_id', 'cashbox_id',
        'cash_transaction_id', 'reversal_cash_transaction_id', 'client_token', 'paid_at', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function cashbox() { return $this->belongsTo(Cashbox::class); }
}
