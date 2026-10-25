<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\Request;
class GlobalSearchController extends Controller {
    public function __invoke(Request $request) {
        $term = trim((string)$request->query('q', ''));
        if (mb_strlen($term) < 2) return response()->json(['data'=>[]]);
        $like = '%'.str_replace(['%','_'], ['\\%','\\_'], $term).'%';
        $data = collect();
        if ($request->user()->hasPermission('products.view')) {
            $data = $data->merge(Product::query()->where(function($q) use ($like) { $q->where('name','like',$like)->orWhere('sku','like',$like)->orWhere('barcode','like',$like); })->limit(8)->get(['id','name','sku','barcode'])->map(fn($p)=>['type'=>'product','id'=>$p->id,'label'=>$p->name,'detail'=>$p->sku ?: $p->barcode,'href'=>'/products']));
        }
        if ($request->user()->hasPermission('customers.view')) {
            $data = $data->merge(Customer::query()->where('name','like',$like)->orWhere('phone','like',$like)->limit(5)->get(['id','name','phone'])->map(fn($p)=>['type'=>'customer','id'=>$p->id,'label'=>$p->name,'detail'=>$p->phone,'href'=>'/customers']));
        }
        if ($request->user()->hasPermission('suppliers.view')) {
            $data = $data->merge(Supplier::query()->where('name','like',$like)->orWhere('phone','like',$like)->limit(5)->get(['id','name','phone'])->map(fn($p)=>['type'=>'supplier','id'=>$p->id,'label'=>$p->name,'detail'=>$p->phone,'href'=>'/suppliers']));
        }
        return response()->json(['data'=>$data->take(20)->values()]);
    }
}
