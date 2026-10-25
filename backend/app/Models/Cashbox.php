<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cashbox extends Model
{
    protected $fillable = ['branch_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function shifts()
    {
        return $this->hasMany(CashboxShift::class);
    }

    public function openShift()
    {
        return $this->hasOne(CashboxShift::class)->where('status', 'open');
    }

    public function transactions()
    {
        return $this->hasMany(CashTransaction::class);
    }
}
