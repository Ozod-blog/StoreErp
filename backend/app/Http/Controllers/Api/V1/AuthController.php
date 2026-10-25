<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\TwoFactorService;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            AuditLogger::log('auth.login_failed', null, null, ['email' => $data['email']], 'failed');
            throw ValidationException::withMessages(['email' => ['Login yoki parol noto‘g‘ri.']]);
        }

        if ($user->status !== 'active') {
            AuditLogger::log('auth.login_blocked', $user, null, null, 'failed', $user->id);
            return response()->json(['message' => 'Hisob faol emas. Administrator bilan bog‘laning.'], 403);
        }
        if ($user->two_factor_confirmed_at) {
            $challenge = Str::random(64);
            Cache::put('erp:2fa-login:' . hash('sha256', $challenge), $user->id, now()->addMinutes(5));
            return response()->json(['two_factor_required' => true, 'challenge_token' => $challenge, 'expires_in' => 300])->header('Cache-Control', 'no-store, private');
        }

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $token = $user->createToken('store-erp-web')->plainTextToken;
        AuditLogger::log('auth.login', $user, null, null, 'success', $user->id);

        return response()->json($this->payload($user) + ['token' => $token])->header('Cache-Control', 'no-store, private');
    }

    public function verifyTwoFactor(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate(['challenge_token' => ['required', 'string', 'size:64'], 'code' => ['required', 'string', 'max:32']]);
        $key = 'erp:2fa-login:' . hash('sha256', $data['challenge_token']);
        $userId = Cache::get($key);
        $throttleKey = 'erp:2fa-attempt:' . hash('sha256', $data['challenge_token']) . ':' . $request->ip();
        if (!$userId || RateLimiter::tooManyAttempts($throttleKey, 5)) return response()->json(['message' => 'Tasdiqlash muddati tugagan yoki urinishlar limiti oshgan. Qayta kiring.'], 429);
        $user = User::find($userId);
        if (!$user || !$user->two_factor_confirmed_at) return response()->json(['message' => '2FA sessiyasi topilmadi.'], 401);
        $valid = $twoFactor->verify((string) $user->two_factor_secret, $data['code']);
        if (!$valid) {
            $codes = $user->two_factor_recovery_codes ?? [];
            $index = $twoFactor->recoveryCodeIndex($codes, $data['code']);
            if ($index !== null) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $valid = true;
            }
        }
        if (!$valid) {
            RateLimiter::hit($throttleKey, 300);
            return response()->json(['message' => 'Tasdiqlash kodi noto‘g‘ri.'], 422);
        }
        RateLimiter::clear($throttleKey);
        Cache::forget($key);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $token = $user->createToken('store-erp-web')->plainTextToken;
        AuditLogger::log('auth.login_2fa', $user, null, null, 'success', $user->id);
        return response()->json($this->payload($user) + ['token' => $token])->header('Cache-Control', 'no-store, private');
    }

    public function twoFactorSetup(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $request->user();
        if ($user->two_factor_confirmed_at) return response()->json(['message' => 'Ikki bosqichli himoya allaqachon yoqilgan.'], 409);
        $secret = $twoFactor->newSecret();
        $user->forceFill(['two_factor_pending_secret' => $secret])->save();
        return response()->json(['secret' => $secret, 'otpauth_url' => $twoFactor->provisioningUri($user, $secret), 'message' => 'Authenticator ilovasiga qo‘shib, tasdiqlash kodini kiriting.'])->header('Cache-Control', 'no-store, private');
    }

    public function twoFactorConfirm(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        if (!$user->two_factor_pending_secret || !$twoFactor->verify((string) $user->two_factor_pending_secret, $data['code'])) return response()->json(['message' => 'Tasdiqlash kodi noto‘g‘ri.'], 422);
        $plain = $twoFactor->recoveryCodes();
        $user->forceFill(['two_factor_secret' => $user->two_factor_pending_secret, 'two_factor_pending_secret' => null, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => array_map(fn($code) => Hash::make($code), $plain)])->save();
        AuditLogger::log('auth.2fa_enabled', $user, null, null, 'success', $user->id);
        return response()->json(['enabled' => true, 'recovery_codes' => $plain, 'message' => 'Tiklash kodlarini xavfsiz joyga saqlang. Ular faqat bir marta ko‘rsatiladi.'])->header('Cache-Control', 'no-store, private');
    }

    public function twoFactorDisable(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        $user = $request->user();
        if (!Hash::check($data['password'], $user->password)) return response()->json(['message' => 'Parol noto‘g‘ri.'], 422);
        $valid = $user->two_factor_secret && $twoFactor->verify((string) $user->two_factor_secret, $data['code']);
        if (!$valid) return response()->json(['message' => '2FA kodi noto‘g‘ri.'], 422);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_pending_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        AuditLogger::log('auth.2fa_disabled', $user, null, null, 'success', $user->id);
        return response()->json(['enabled' => false]);
    }

    /** Parolni unutgan foydalanuvchiga email orqali bir martalik tiklash kodi yuboradi. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        $email = mb_strtolower(trim($data['email']));
        $emailKey = hash('sha256', $email);
        $limitKey = 'erp:password-reset:send:' . $emailKey . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($limitKey, 3)) {
            return response()->json(['message' => 'Agar bu email faol hisobga tegishli bo‘lsa, tiklash ko‘rsatmasi yuboriladi. Yangi so‘rovdan oldin biroz kuting.'], 429);
        }
        RateLimiter::hit($limitKey, 3600);

        // Hisob mavjudligini oshkor qilmaslik uchun har doim bir xil javob qaytaramiz.
        $user = User::whereRaw('LOWER(email) = ?', [$email])->where('status', 'active')->first();
        if ($user) {
            $code = (string) random_int(100000, 999999);
            try {
                Mail::raw("Store ERP parolingizni tiklash kodi: {$code}\n\nKod 10 daqiqa amal qiladi. Bu so‘rovni siz boshlamagan bo‘lsangiz, xabarni e’tiborsiz qoldiring. Kodni hech kimga bermang.", function ($message) use ($email) {
                    $message->to($email)->subject('Store ERP — parolni tiklash');
                });
                Cache::put('erp:password-reset:' . $emailKey, [
                    'user_id' => $user->id,
                    'code_hash' => Hash::make($code),
                    'attempts' => 0,
                ], now()->addMinutes(10));
                AuditLogger::log('auth.password_reset_requested', $user, null, ['channel' => 'email'], 'success', $user->id);
            } catch (\Throwable $e) {
                report($e);
                // Email yetkazilmasa ham endpoint hisob mavjudligini oshkor qilmaydi.
            }
        }

        return response()->json(['message' => 'Agar bu email faol hisobga tegishli bo‘lsa, parolni tiklash kodi yuborildi. Emailingizni tekshiring.'])->header('Cache-Control', 'no-store, private');
    }

    /** Emailga kelgan bir martalik kod bilan parolni almashtiradi va eski tokenlarni bekor qiladi. */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $emailKey = hash('sha256', $email);
        $key = 'erp:password-reset:' . $emailKey;
        $attemptKey = 'erp:password-reset:verify:' . $emailKey . ':' . $request->ip();
        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            return response()->json(['message' => 'Juda ko‘p noto‘g‘ri urinish. Yangi kod so‘rashdan oldin kuting.'], 429);
        }
        $record = Cache::get($key);
        $user = $record ? User::whereKey($record['user_id'] ?? 0)->whereRaw('LOWER(email) = ?', [$email])->where('status', 'active')->first() : null;
        if (!$record || !$user || !Hash::check($data['code'], $record['code_hash'] ?? '')) {
            RateLimiter::hit($attemptKey, 600);
            if ($record) {
                $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
                if ($record['attempts'] >= 5) Cache::forget($key);
                else Cache::put($key, $record, now()->addMinutes(10));
            }
            return response()->json(['message' => 'Kod noto‘g‘ri yoki muddati tugagan. Yangi kod so‘rang.'], 422);
        }

        DB::transaction(function () use ($user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['password' => Hash::make($data['password']), 'remember_token' => Str::random(60)])->save();
            $locked->tokens()->delete();
        });
        Cache::forget($key);
        RateLimiter::clear($attemptKey);
        AuditLogger::log('auth.password_reset_completed', $user, null, ['channel' => 'email', 'sessions_revoked' => true], 'success', $user->id);

        return response()->json(['message' => 'Parol muvaffaqiyatli yangilandi. Xavfsizlik uchun barcha eski sessiyalar bekor qilindi. Yangi parol bilan kiring.'])->header('Cache-Control', 'no-store, private');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()?->delete();
        AuditLogger::log('auth.logout', $user, null, null, 'success', $user->id);

        return response()->json(['message' => 'Tizimdan chiqildi.']);
    }


    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles:id,name,slug');
        $activity = AuditLog::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(15)
            ->get(['id', 'action', 'result', 'created_at', 'auditable_type', 'auditable_id']);

        return response()->json([
            'user' => $user,
            'permissions' => $user->permissionSlugs(),
            'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
            'activity' => $activity,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $user = $request->user();
        $user->forceFill(['name' => trim($data['name'])])->save();
        AuditLogger::log('auth.profile_updated', $user, null, ['fields' => ['name']], 'success', $user->id);
        return response()->json(['message' => 'Profil ma’lumotlari saqlandi.', 'user' => $user->fresh()->load('roles:id,name,slug')]);
    }

    public function sendEmailChangeCode(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $email = mb_strtolower(trim($data['email']));
        $user = $request->user();
        if (mb_strtolower($user->email) === $email) {
            return response()->json(['message' => 'Bu email profilingizda allaqachon ishlatilmoqda.'], 422);
        }
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json(['message' => 'Bu email boshqa foydalanuvchi tomonidan ishlatilmoqda.'], 422);
        }
        $limitKey = 'erp:email-change-send:' . $user->id;
        if (RateLimiter::tooManyAttempts($limitKey, 3)) {
            return response()->json(['message' => 'Kod yuborish limiti oshdi. Birozdan keyin qayta urinib ko‘ring.'], 429);
        }
        $code = (string) random_int(100000, 999999);
        try {
            Mail::raw("Store ERP profilingizdagi email manzilini almashtirish kodi: {$code}\n\nKod 5 daqiqa amal qiladi. Agar bu so‘rovni siz boshlamagan bo‘lsangiz, xabarni e’tiborsiz qoldiring.", function ($message) use ($email) {
                $message->to($email)->subject('Store ERP — emailni tasdiqlash kodi');
            });
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Email yuborilmadi. Backend .env faylidagi SMTP/MAIL sozlamalarini tekshiring.'], 503);
        }
        $key = 'erp:email-change:' . $user->id;
        Cache::put($key, ['email' => $email, 'code_hash' => Hash::make($code), 'attempts' => 0], now()->addMinutes(5));
        RateLimiter::hit($limitKey, 3600);
        AuditLogger::log('auth.email_change_code_sent', $user, null, ['email' => $email], 'success', $user->id);
        return response()->json(['message' => '6 xonali tasdiqlash kodi yangi email manziliga yuborildi.', 'expires_in' => 300]);
    }

    public function confirmEmailChange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'digits:6']]);
        $user = $request->user();
        $key = 'erp:email-change:' . $user->id;
        $pending = Cache::get($key);
        if (!$pending) return response()->json(['message' => 'Kod muddati tugagan. Yangi kod so‘rang.'], 422);
        if (($pending['attempts'] ?? 0) >= 5) {
            Cache::forget($key);
            return response()->json(['message' => 'Urinishlar limiti oshdi. Yangi kod so‘rang.'], 429);
        }
        if (!Hash::check($data['code'], $pending['code_hash'])) {
            $pending['attempts'] = ($pending['attempts'] ?? 0) + 1;
            Cache::put($key, $pending, now()->addMinutes(5));
            return response()->json(['message' => 'Tasdiqlash kodi noto‘g‘ri.'], 422);
        }
        $email = $pending['email'];
        if (User::whereRaw('LOWER(email) = ?', [$email])->where('id', '<>', $user->id)->exists()) {
            Cache::forget($key);
            return response()->json(['message' => 'Bu email boshqa foydalanuvchi tomonidan ishlatilmoqda.'], 422);
        }
        DB::transaction(function () use ($user, $email) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (User::whereRaw('LOWER(email) = ?', [$email])->where('id', '<>', $lockedUser->id)->exists()) {
                throw ValidationException::withMessages(['email' => ['Bu email boshqa foydalanuvchi tomonidan ishlatilmoqda.']]);
            }
            $lockedUser->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
        });
        Cache::forget($key);
        AuditLogger::log('auth.email_changed', $user, null, ['email' => $email], 'success', $user->id);
        return response()->json(['message' => 'Email manzili tasdiqlandi va muvaffaqiyatli almashtirildi.', 'user' => $user->fresh()->load('roles:id,name,slug')]);
    }


    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();
        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Joriy parol noto‘g‘ri.'], 422);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        AuditLogger::log('auth.password_changed', $user, null, ['changed' => true], 'success', $user->id);

        return response()->json(['message' => 'Parol muvaffaqiyatli almashtirildi.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    private function payload(User $user): array
    {
        return [
            'user' => $user->load('roles:id,name,slug'),
            'permissions' => $user->permissionSlugs(),
            'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
        ];
    }
}
