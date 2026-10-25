<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\TelegramRecipient;
use Illuminate\Http\Request;
class TelegramRecipientController extends Controller {
 public function index(){return TelegramRecipient::query()->select(['id','user_id','chat_id','label','event_types','is_active','created_at'])->orderBy('id')->get();}
 public function store(Request $request){$data=$request->validate(['chat_id'=>'required|string|max:100','label'=>'nullable|string|max:255','event_types'=>'nullable|array','event_types.*'=>'string|in:low_stock,shift_difference,debt_due,expiry,daily_report','is_active'=>'sometimes|boolean']);$data['user_id']=$request->user()->id;$recipient=TelegramRecipient::create($data);return response()->json(['data'=>$recipient->makeHidden([])->only(['id','user_id','chat_id','label','event_types','is_active','created_at'])],201);}
 public function update(Request $request,TelegramRecipient $telegramRecipient){$data=$request->validate(['label'=>'sometimes|nullable|string|max:255','event_types'=>'sometimes|nullable|array','event_types.*'=>'string|in:low_stock,shift_difference,debt_due,expiry,daily_report','is_active'=>'sometimes|boolean']);$telegramRecipient->update($data);return response()->json(['success'=>true]);}
 public function destroy(TelegramRecipient $telegramRecipient){$telegramRecipient->delete();return response()->json(['success'=>true]);}
}
