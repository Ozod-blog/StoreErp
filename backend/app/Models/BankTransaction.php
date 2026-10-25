<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BankTransaction extends Model { protected $fillable=['number','type','from_account_id','to_account_id','cashbox_id','amount','description','status','created_by','approved_by','occurred_at','client_token']; protected $casts=['amount'=>'decimal:2','occurred_at'=>'datetime']; public function fromAccount(){return $this->belongsTo(BankAccount::class,'from_account_id');} public function toAccount(){return $this->belongsTo(BankAccount::class,'to_account_id');} public function cashbox(){return $this->belongsTo(Cashbox::class);} }
