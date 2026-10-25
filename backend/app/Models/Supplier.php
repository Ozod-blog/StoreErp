<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'phone', 'address', 'contact_info', 'note', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
