<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
class NotificationController extends Controller {
    public function index(Request $request) {
        $user = $request->user();
        return response()->json([
            'data' => $user->notifications()->latest()->paginate(max(5, min((int)$request->query('per_page', 20), 100))),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }
    public function unreadCount(Request $request) { return response()->json(['count' => $request->user()->unreadNotifications()->count()]); }
    public function read(Request $request, string $id) {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();
        return response()->json(['success'=>true]);
    }
    public function readAll(Request $request) { $request->user()->unreadNotifications()->update(['read_at'=>now()]); return response()->json(['success'=>true]); }
}
