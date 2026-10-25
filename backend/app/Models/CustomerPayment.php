<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPayment extends Model
{
    protected $fillable = [
        'number', 'customer_id', 'amount', 'method', 'reference', 'note', 'status', 'shift_id', 'cashbox_id',
        'cash_transaction_id', 'reversal_cash_transaction_id', 'is_online_verified', 'client_token', 'paid_at', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = ['amount' => 'decimal:2', 'is_online_verified' => 'boolean', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function cashbox() { return $this->belongsTo(Cashbox::class); }
}
