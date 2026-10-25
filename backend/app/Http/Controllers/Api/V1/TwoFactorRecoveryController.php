<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TwoFactorRecoveryController extends Controller
{
    public function startEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required','email:rfc','max:254']]);
        $email = mb_strtolower(trim($data['email']));
        $key = 'erp:2fa-recovery-send:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) return response()->json(['message' => 'Juda ko‘p urinish. Keyinroq qayta urinib ko‘ring.'], 429);
        RateLimiter::hit($key, 3600);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        // Do not disclose whether an email exists or has 2FA enabled.
        if ($user && $user->two_factor_confirmed_at && $user->email_verified_at) {
            $code = (string) random_int(100000, 999999);
            Cache::put('erp:2fa-recovery-email:'.hash('sha256', $email), [
                'user_id' => $user->id, 'hash' => Hash::make($code), 'attempts' => 0,
            ], now()->addMinutes(5));
            try {
                Mail::raw("Store ERP 2FA tiklash kodi: {$code}\nKod 5 daqiqa amal qiladi. Agar so‘rov sizdan bo‘lmasa, e’tiborsiz qoldiring.", function ($m) use ($email) {
                    $m->to($email)->subject('Store ERP — 2FA hisobni tiklash');
                });
            } catch (\Throwable $e) {
                report($e);
                Cache::forget('erp:2fa-recovery-email:'.hash('sha256', $email));
                return response()->json(['message' => 'Email yuborilmadi. Serverning MAIL/SMTP sozlamalarini tekshiring.'], 503);
            }
        }
        return response()->json(['message' => 'Agar email tasdiqlangan va 2FA yoqilgan bo‘lsa, tiklash kodi yuborildi.', 'expires_in' => 300]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required','email:rfc','max:254'], 'code' => ['required','digits:6']]);
        $email = mb_strtolower(trim($data['email']));
        $key = 'erp:2fa-recovery-email:'.hash('sha256', $email);
        $pending = Cache::get($key);
        if (!$pending || ($pending['attempts'] ?? 0) >= 5) {
            Cache::forget($key);
            return response()->json(['message' => 'Kod muddati tugagan yoki urinishlar limiti oshgan.'], 422);
        }
        if (!Hash::check($data['code'], $pending['hash'])) {
            $pending['attempts']++;
            Cache::put($key, $pending, now()->addMinutes(5));
            return response()->json(['message' => 'Tiklash kodi noto‘g‘ri.'], 422);
        }
        Cache::forget($key);
        $user = User::find($pending['user_id']);
        if (!$user || !$user->two_factor_confirmed_at || mb_strtolower($user->email) !== $email) {
            return response()->json(['message' => 'Tiklash so‘rovini bajarib bo‘lmadi.'], 422);
        }
        DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'two_factor_secret' => null, 'two_factor_pending_secret' => null,
                'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
            ])->save();
            // Revoke old sessions after recovery so old stolen tokens cannot remain valid.
            $locked->tokens()->delete();
        });
        AuditLogger::log('auth.2fa_recovered_by_email', $user, null, ['method' => 'verified_email'], 'success', $user->id);
        try { Mail::raw('Store ERP hisobingizda 2FA email orqali tiklandi va eski sessiyalar bekor qilindi. Keyingi kirishda yangi Authenticator ulang. Agar bu siz bo‘lmasangiz, administratorga murojaat qiling.', function ($m) use ($email) { $m->to($email)->subject('Store ERP — 2FA tiklandi'); }); } catch (\Throwable $e) { report($e); }
        return response()->json(['message' => 'Email tasdiqlandi. 2FA qayta tiklandi; xavfsizlik uchun qayta login qiling va yangi Authenticator ulang.', 'two_factor_enabled' => false]);
    }

    public function requestAdminRecovery(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required','string','min:10','max:1000']]);
        $user = $request->user();
        if (!$user->two_factor_confirmed_at) return response()->json(['message' => 'Hisobda 2FA yoqilmagan.'], 422);
        $existing = DB::table('two_factor_recovery_requests')->where('user_id', $user->id)->where('status', 'pending')->exists();
        if ($existing) return response()->json(['message' => 'Sizda ko‘rib chiqilayotgan tiklash so‘rovi mavjud.'], 409);
        $id = DB::table('two_factor_recovery_requests')->insertGetId([
            'user_id' => $user->id, 'created_by' => $user->id, 'status' => 'pending', 'reason' => $data['reason'],
            'request_ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditLogger::log('auth.2fa_admin_recovery_requested', $user, null, ['request_id' => $id], 'success', $user->id);
        return response()->json(['message' => 'Tiklash so‘rovi yuborildi. Administrator shaxsingizni tekshiradi.', 'request_id' => $id], 201);
    }

    public function createAdminRequestForUser(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->isPrivileged($actor)) return response()->json(['message' => 'Ruxsat berilmagan.'], 403);
        $data = $request->validate(['user_id' => ['required','integer','exists:users,id'], 'reason' => ['required','string','min:10','max:1000']]);
        if ((int)$data['user_id'] === (int)$actor->id) return response()->json(['message' => 'Administrator o‘zining tiklash so‘rovini yaratolmaydi.'], 422);
        $target = User::findOrFail($data['user_id']);
        if (!$target->two_factor_confirmed_at) return response()->json(['message' => 'Tanlangan foydalanuvchida 2FA yoqilmagan.'], 422);
        $existing = DB::table('two_factor_recovery_requests')->where('user_id', $target->id)->where('status', 'pending')->exists();
        if ($existing) return response()->json(['message' => 'Ushbu foydalanuvchida ko‘rib chiqilayotgan so‘rov mavjud.'], 409);
        $id = DB::table('two_factor_recovery_requests')->insertGetId([
            'user_id' => $target->id, 'created_by' => $actor->id, 'status' => 'pending', 'reason' => $data['reason'],
            'request_ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditLogger::log('auth.2fa_admin_recovery_created_by_admin', $actor, null, ['request_id' => $id, 'target_user_id' => $target->id], 'success', $actor->id);
        return response()->json(['message' => 'Tiklash so‘rovi yaratildi. Ikkinchi vakolatli tekshiruvdan so‘ng tasdiqlang.', 'request_id' => $id], 201);
    }

    public function adminRequests(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request->user())) return response()->json(['message' => 'Ruxsat berilmagan.'], 403);
        $rows = DB::table('two_factor_recovery_requests as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.reviewed_by')
            ->select('r.id','r.user_id','u.name','u.email','r.status','r.reason','r.request_ip','r.created_at','r.reviewed_at','reviewer.name as reviewer_name')
            ->orderByDesc('r.created_at')->limit(100)->get();
        return response()->json(['requests' => $rows]);
    }

    public function reviewAdminRequest(Request $request, int $id): JsonResponse
    {
        $reviewer = $request->user();
        if (!$this->isPrivileged($reviewer)) return response()->json(['message' => 'Ruxsat berilmagan.'], 403);
        $data = $request->validate(['decision' => ['required','in:approve,reject'], 'review_note' => ['nullable','string','max:1000']]);
        $result = DB::transaction(function () use ($id, $reviewer, $request, $data) {
            $row = DB::table('two_factor_recovery_requests')->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'pending') return ['error' => 'So‘rov topilmadi yoki allaqachon ko‘rib chiqilgan.'];
            if ((int)$row->user_id === (int)$reviewer->id) return ['error' => 'Administrator o‘zining 2FA tiklashini tasdiqlay olmaydi.'];
            if (!empty($row->created_by) && (int)$row->created_by === (int)$reviewer->id) return ['error' => 'So‘rovni yaratgan administrator uni o‘zi tasdiqlay olmaydi. Boshqa administrator tasdiqlashi kerak.'];
            $target = User::whereKey($row->user_id)->lockForUpdate()->first();
            if (!$target) return ['error' => 'Foydalanuvchi topilmadi.'];
            $status = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            if ($status === 'approved') {
                $target->forceFill(['two_factor_secret' => null, 'two_factor_pending_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
                $target->tokens()->delete();
            }
            DB::table('two_factor_recovery_requests')->where('id', $id)->update([
                'status' => $status, 'reviewed_by' => $reviewer->id, 'review_ip' => $request->ip(),
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            return ['status' => $status, 'user_id' => $target->id, 'email' => $target->email];
        });
        if (isset($result['error'])) return response()->json(['message' => $result['error']], 422);
        AuditLogger::log('auth.2fa_admin_recovery_reviewed', $reviewer, null, ['request_id' => $id, 'decision' => $result['status'], 'target_user_id' => $result['user_id'], 'review_note' => $data['review_note'] ?? null], 'success', $reviewer->id);
        if ($result['status'] === 'approved') {
            try { Mail::raw('Store ERP 2FA tiklash so‘rovingiz administrator tomonidan tasdiqlandi. Eski sessiyalar bekor qilindi. Qayta login qilib, yangi Authenticator ulang.', function ($m) use ($result) { $m->to($result['email'])->subject('Store ERP — 2FA tiklash tasdiqlandi'); }); } catch (\Throwable $e) { report($e); }
        }
        return response()->json(['message' => $result['status'] === 'approved' ? '2FA tiklandi, eski sessiyalar bekor qilindi.' : 'Tiklash so‘rovi rad etildi.', 'status' => $result['status']]);
    }

    private function isPrivileged(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('owner');
    }
}
