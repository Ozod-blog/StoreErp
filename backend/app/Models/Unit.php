<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['name', 'short_name', 'allow_fraction', 'is_active'];

    protected $casts = ['allow_fraction' => 'boolean', 'is_active' => 'boolean'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
