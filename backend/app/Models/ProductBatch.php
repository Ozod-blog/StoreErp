<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductBatch extends Model {
    protected $fillable = ['product_id','warehouse_id','batch_number','received_at','expires_at','quantity_received','quantity_remaining','unit_cost','source_type','source_id'];
    protected $casts = ['received_at'=>'date','expires_at'=>'date','quantity_received'=>'decimal:3','quantity_remaining'=>'decimal:3','unit_cost'=>'decimal:4'];
    public function product(){ return $this->belongsTo(Product::class); }
    public function warehouse(){ return $this->belongsTo(Warehouse::class); }
}
