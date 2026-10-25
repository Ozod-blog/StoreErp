<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['code', 'name', 'address', 'phone', 'manager_name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }
}
