<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashboxShift extends Model
{
    protected $fillable = [
        'cashbox_id', 'user_id', 'employee_id', 'status', 'opened_at', 'opening_cash', 'opening_note',
        'handover_from_shift_id', 'closed_at', 'closed_by', 'expected_cash', 'actual_cash', 'difference',
        'difference_reason', 'handed_over_to_user_id', 'reviewed_by', 'reviewed_at', 'review_note', 'difference_notified_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'difference_notified_at' => 'datetime',
        'opening_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'difference' => 'decimal:2',
    ];

    public function cashbox()
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function handedOverTo()
    {
        return $this->belongsTo(User::class, 'handed_over_to_user_id');
    }

    public function transactions()
    {
        return $this->hasMany(CashTransaction::class, 'shift_id');
    }
}
