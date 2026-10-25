<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'sale_id', 'shift_id', 'cashbox_id', 'user_id', 'direction', 'kind', 'method', 'amount',
        'reference', 'cash_transaction_id', 'is_online_verified',
    ];

    protected $casts = ['amount' => 'decimal:2', 'is_online_verified' => 'boolean'];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
