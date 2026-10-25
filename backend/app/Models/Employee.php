<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = ['user_id', 'employee_code', 'phone', 'position', 'hire_date', 'salary', 'status', 'notes'];
    protected $casts = ['hire_date' => 'date', 'salary' => 'decimal:2'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
