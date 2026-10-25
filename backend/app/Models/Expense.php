<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Expense extends Model { protected $fillable=['number','category','expense_date','amount','payment_method','cashbox_id','bank_account_id','branch_id','employee_id','description','attachment_path','status','created_by','approved_by','bank_transaction_id']; protected $casts=['expense_date'=>'date','amount'=>'decimal:2']; public function bankAccount(){return $this->belongsTo(BankAccount::class);} public function cashbox(){return $this->belongsTo(Cashbox::class);} public function branch(){return $this->belongsTo(Branch::class);} }
