<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BankAccount extends Model { protected $fillable=['branch_id','name','account_number','currency','opening_balance','is_active']; protected $casts=['opening_balance'=>'decimal:2','is_active'=>'boolean']; public function branch(){return $this->belongsTo(Branch::class);} public function transactions(){return $this->hasMany(BankTransaction::class,'from_account_id');} }
