<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
class SessionController extends Controller {
 public function index(Request $request){$current=$request->user()->currentAccessToken();return response()->json(['current_token_id'=>$current?->getKey(),'data'=>$request->user()->tokens()->orderByDesc('last_used_at')->get(['id','name','last_used_at','created_at','expires_at'])]);}
 public function destroy(Request $request,int $token){$personal=$request->user()->tokens()->whereKey($token)->firstOrFail();$personal->delete();return response()->json(['success'=>true,'current_session'=>$request->user()->currentAccessToken()?->getKey()===$token]);}
 public function destroyOthers(Request $request){$current=$request->user()->currentAccessToken();$request->user()->tokens()->when($current,fn($q)=>$q->where('id','!=',$current->getKey()))->delete();return response()->json(['success'=>true]);}
}
