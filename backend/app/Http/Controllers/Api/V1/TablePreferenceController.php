<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class TablePreferenceController extends Controller {
 public function show(Request $request,string $tableKey){abort_unless(preg_match('/^[a-z0-9_-]{1,100}$/',$tableKey),422);$row=DB::table('user_table_preferences')->where('user_id',$request->user()->id)->where('table_key',$tableKey)->first();return response()->json(['data'=>$row?['hidden_columns'=>json_decode($row->hidden_columns??'[]',true),'saved_filters'=>json_decode($row->saved_filters??'[]',true),'density'=>$row->density]:['hidden_columns'=>[],'saved_filters'=>[],'density'=>'comfortable']]);}
 public function update(Request $request,string $tableKey){abort_unless(preg_match('/^[a-z0-9_-]{1,100}$/',$tableKey),422);$data=$request->validate(['hidden_columns'=>'nullable|array|max:100','hidden_columns.*'=>'string|max:100','saved_filters'=>'nullable|array|max:50','density'=>'nullable|in:comfortable,compact']);DB::table('user_table_preferences')->updateOrInsert(['user_id'=>$request->user()->id,'table_key'=>$tableKey],['hidden_columns'=>json_encode($data['hidden_columns']??[]),'saved_filters'=>json_encode($data['saved_filters']??[]),'density'=>$data['density']??'comfortable','updated_at'=>now(),'created_at'=>now()]);return response()->json(['success'=>true]);}
}
