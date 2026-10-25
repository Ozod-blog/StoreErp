<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LoyaltyTransaction extends Model {
    protected $fillable = ['loyalty_account_id','sale_id','points_delta','reason','created_by'];
    public function account(){ return $this->belongsTo(LoyaltyAccount::class,'loyalty_account_id'); }
}
