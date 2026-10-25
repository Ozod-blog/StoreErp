<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(5, min((int) $request->query('per_page', 25), 100));
        $action = trim((string) $request->query('action', ''));

        return AuditLog::with('user:id,name,email')
            ->when($action !== '', fn ($q) => $q->where('action', 'like', addcslashes($action, '%_\\').'%'))
            ->when(in_array($request->query('result'), ['success', 'failed'], true), fn ($q) => $q->where('result', $request->query('result')))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', (int) $id))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }
    public function export(Request $request)
    {
        $query = AuditLog::with('user:id,name,email')
            ->when(trim((string)$request->query('action','')) !== '', fn($q) => $q->where('action','like',addcslashes(trim((string)$request->query('action')),'%_\\').'%'))
            ->when(in_array($request->query('result'), ['success','failed'], true), fn($q) => $q->where('result',$request->query('result')))
            ->when($request->query('user_id'), fn($q,$id) => $q->where('user_id',(int)$id))
            ->when($request->query('from'), fn($q,$d) => $q->where('created_at','>=',$d))
            ->when($request->query('to'), fn($q,$d) => $q->where('created_at','<=',$d.' 23:59:59'))
            ->orderByDesc('id')->limit(10000);
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output','w');
            fputcsv($out, ['id','created_at','user','email','action','result','entity_type','entity_id','ip_address','old_values','new_values']);
            foreach ($query->get() as $row) fputcsv($out, [$row->id,$row->created_at,$row->user?->name,$row->user?->email,$row->action,$row->result,$row->auditable_type,$row->auditable_id,$row->ip_address,json_encode($row->old_values,JSON_UNESCAPED_UNICODE),json_encode($row->new_values,JSON_UNESCAPED_UNICODE)]);
            fclose($out);
        }, 'store-erp-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type'=>'text/csv; charset=UTF-8']);
    }

}
