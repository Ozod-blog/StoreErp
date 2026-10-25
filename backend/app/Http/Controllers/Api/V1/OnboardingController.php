<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OnboardingController extends Controller {
 private const STEPS=['store','branch','warehouse','first_product'];
 public function index(){return response()->json(['steps'=>DB::table('erp_onboarding')->whereIn('step_key',self::STEPS)->orderBy('id')->get()]);}
 public function complete(Request $request,string $step){abort_unless(in_array($step,self::STEPS,true),404);$data=$request->validate(['payload'=>'nullable|array']);DB::table('erp_onboarding')->updateOrInsert(['step_key'=>$step],['is_completed'=>true,'payload'=>json_encode($data['payload']??[]),'completed_by'=>$request->user()->id,'completed_at'=>now(),'updated_at'=>now(),'created_at'=>now()]);return response()->json(['success'=>true,'step'=>$step]);}
}
