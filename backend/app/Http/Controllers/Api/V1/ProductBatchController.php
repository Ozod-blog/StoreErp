<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\ProductBatch;
use Illuminate\Http\Request;
class ProductBatchController extends Controller {
 public function index(Request $request){$days=max(0,min((int)$request->query('expiring_days',30),365));return ProductBatch::with(['product:id,name,sku','warehouse:id,name'])->when($request->query('warehouse_id'),fn($q,$id)=>$q->where('warehouse_id',$id))->when($request->boolean('expiring_only'),fn($q)=>$q->whereNotNull('expires_at')->whereDate('expires_at','<=',now()->addDays($days)->toDateString()))->orderByRaw('expires_at is null')->orderBy('expires_at')->paginate(max(5,min((int)$request->query('per_page',25),100))); }
 public function store(Request $request){$data=$request->validate(['product_id'=>'required|integer|exists:products,id','warehouse_id'=>'required|integer|exists:warehouses,id','batch_number'=>'required|string|max:100','received_at'=>'nullable|date','expires_at'=>'nullable|date|after_or_equal:received_at','quantity_received'=>'required|numeric|min:0.001','quantity_remaining'=>'nullable|numeric|min:0','unit_cost'=>'required|numeric|min:0']);$data['quantity_remaining']=$data['quantity_remaining']??$data['quantity_received'];return response()->json(['data'=>ProductBatch::create($data)],201);}
}
