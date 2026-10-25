<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSchedule extends Model
{
    public const TYPES = ['work', 'off', 'vacation', 'sick', 'absent_excused', 'absent_unexcused'];

    protected $fillable = ['employee_id', 'work_date', 'type', 'start_time', 'end_time', 'note', 'created_by'];

    protected $casts = ['work_date' => 'date:Y-m-d'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
