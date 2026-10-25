<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LoyaltyAccount extends Model {
    protected $fillable = ['customer_id','card_code','points_balance','lifetime_points','is_active'];
    protected $casts = ['points_balance'=>'integer','lifetime_points'=>'integer','is_active'=>'boolean'];
    public function customer(){ return $this->belongsTo(Customer::class); }
    public function transactions(){ return $this->hasMany(LoyaltyTransaction::class); }
}
