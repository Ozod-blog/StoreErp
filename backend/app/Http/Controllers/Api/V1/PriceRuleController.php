<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\PriceRule;
use Illuminate\Http\Request;
class PriceRuleController extends Controller {
 public function index(Request $request){return PriceRule::with(['product:id,name,sku','category:id,name','customer:id,name'])->when($request->query('kind'),fn($q,$kind)=>$q->where('kind',$kind))->orderByDesc('id')->paginate(max(5,min((int)$request->query('per_page',25),100))); }
 public function store(Request $request){$data=$request->validate(['name'=>'required|string|max:255','kind'=>'required|in:promotion,customer_discount,loyalty_redemption','discount_type'=>'required|in:percent,fixed','discount_value'=>'required|numeric|min:0','product_id'=>'nullable|integer|exists:products,id','category_id'=>'nullable|integer|exists:categories,id','customer_id'=>'nullable|integer|exists:customers,id','customer_card_code'=>'nullable|string|max:80','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after_or_equal:starts_at','is_active'=>'sometimes|boolean']);$data['created_by']=$request->user()->id;return response()->json(['data'=>PriceRule::create($data)],201);}
 public function update(Request $request,PriceRule $priceRule){$data=$request->validate(['name'=>'sometimes|required|string|max:255','kind'=>'sometimes|required|in:promotion,customer_discount,loyalty_redemption','discount_type'=>'sometimes|required|in:percent,fixed','discount_value'=>'sometimes|required|numeric|min:0','product_id'=>'nullable|integer|exists:products,id','category_id'=>'nullable|integer|exists:categories,id','customer_id'=>'nullable|integer|exists:customers,id','customer_card_code'=>'nullable|string|max:80','starts_at'=>'nullable|date','ends_at'=>'nullable|date','is_active'=>'sometimes|boolean']);$priceRule->update($data);return response()->json(['data'=>$priceRule->fresh()]);}
}
