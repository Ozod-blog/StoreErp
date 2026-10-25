<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PriceRule extends Model {
    protected $fillable = ['name','kind','discount_type','discount_value','product_id','category_id','customer_id','customer_card_code','starts_at','ends_at','is_active','created_by'];
    protected $casts = ['discount_value'=>'decimal:2','starts_at'=>'datetime','ends_at'=>'datetime','is_active'=>'boolean'];
    public function product(){ return $this->belongsTo(Product::class); }
    public function category(){ return $this->belongsTo(Category::class); }
    public function customer(){ return $this->belongsTo(Customer::class); }
}
