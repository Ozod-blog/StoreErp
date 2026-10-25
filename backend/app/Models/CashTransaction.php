<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    protected $fillable = [
        'cashbox_id', 'shift_id', 'user_id', 'direction', 'category', 'amount', 'balance_after',
        'note', 'source_type', 'source_id', 'client_token', 'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function cashbox()
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function shift()
    {
        return $this->belongsTo(CashboxShift::class, 'shift_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
