<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3-bosqich: Savdo oynasi (POS), Xavfsizlik, Sessiyalar va Telegram sahifalari statistikasi.
 * Javob tuzilmasi StatsController bilan bir xil: { title, period, kpis[], charts[] } — InsightsPanel uni o'zi chizadi.
 *
 * Ko'lam (scope):
 *  - pos:       sales.view bor bo'lsa — butun do'kon, aks holda faqat kassirning o'z savdolari
 *  - security:  users.view bor bo'lsa — butun tizim, aks holda faqat foydalanuvchining o'z hisobi
 *  - sessions:  users.view bor bo'lsa — barcha foydalanuvchilar, aks holda faqat o'z sessiyalari
 *  - telegram:  users.view (qabul qiluvchilar sahifasi bilan bir xil)
 */
class StatsSystemController extends Controller
{
    private const DAYS = 30;

    private const METHODS = ['cash' => 'Naqd pul', 'card' => 'Bank kartasi', 'click' => 'Click', 'payme' => 'Payme', 'transfer' => 'Bank o‘tkazmasi'];

    private const AUTH_EVENTS = [
        'auth.login' => 'Tizimga kirish', 'auth.login_2fa' => 'Kirish (2FA bilan)', 'auth.login_failed' => 'Noto‘g‘ri kirish urinishi',
        'auth.login_blocked' => 'Bloklangan hisobga urinish', 'auth.logout' => 'Chiqish', 'auth.2fa_enabled' => '2FA yoqildi', 'auth.2fa_disabled' => '2FA o‘chirildi',
        'auth.password_changed' => 'Parol o‘zgartirildi', 'auth.password_reset_requested' => 'Parolni tiklash so‘raldi', 'auth.password_reset_completed' => 'Parol tiklandi',
        'auth.profile_updated' => 'Profil yangilandi', 'auth.email_change_code_sent' => 'Email o‘zgartirish kodi yuborildi', 'auth.email_changed' => 'Email o‘zgartirildi',
    ];

    private const TG_EVENTS = [
        'low_stock' => 'Kam qoldiq', 'shift_difference' => 'Smena farqi', 'debt_due' => 'Qarz muddati', 'expiry' => 'Yaroqlilik muddati', 'daily_report' => 'Kunlik hisobot',
    ];

    // ------------------------------------------------------------------ yordamchilar

    private function nice(?string $s): string
    {
        $s = trim(str_replace(['_', '.'], ' ', (string) $s));

        return $s === '' ? '—' : mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private function m($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    private function kpi(string $title, $value, string $format = 'int', ?string $note = null, string $tone = 'blue', string $icon = 'activity'): array
    {
        return ['title' => $title, 'value' => $value, 'format' => $format, 'note' => $note, 'tone' => $tone, 'icon' => $icon];
    }

    private function chart(string $type, string $title, array $data, array $extra = []): array
    {
        return array_merge(['type' => $type, 'title' => $title, 'data' => $data], $extra);
    }

    private function respond(string $title, array $kpis, array $charts, ?string $period = null)
    {
        return response()->json([
            'title' => $title,
            'period' => $period ?? 'So‘nggi '.self::DAYS.' kun',
            'kpis' => $kpis,
            'charts' => array_values(array_filter($charts)),
        ]);
    }

    private function from(): Carbon
    {
        return Carbon::today()->subDays(self::DAYS - 1);
    }

    /** [{label:'dd.mm', <keys>}] — bo'sh kunlar 0 bilan to'ldiriladi. */
    private function dailySeries($rows, array $keys): array
    {
        $by = [];
        foreach ($rows as $r) {
            $by[substr((string) $r->d, 0, 10)] = $r;
        }
        $out = [];
        $today = Carbon::today();
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $row = ['label' => $d->format('d.m')];
            foreach ($keys as $k) {
                $row[$k] = isset($by[$d->toDateString()]) ? (float) $by[$d->toDateString()]->$k : 0;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function hourExpr(string $col): string
    {
        return DB::getDriverName() === 'pgsql' ? "EXTRACT(HOUR FROM $col)" : "CAST(strftime('%H', $col) AS INTEGER)";
    }

    private function lv($rows, string $labelKey = 'name', string $valueKey = 'total', ?callable $labelFn = null): array
    {
        return collect($rows)->map(fn ($r) => ['label' => $labelFn ? $labelFn($r->$labelKey) : (string) $r->$labelKey, 'value' => (float) $r->$valueKey])->values()->all();
    }

    private function stamp($v): string
    {
        return $v ? substr((string) $v, 0, 16) : '—';
    }

    // ------------------------------------------------------------------ SAVDO OYNASI (POS)

    public function pos(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('sales.create'), 403);
        $all = $user->can('sales.view');
        $today = Carbon::today()->toDateString();
        $from = $this->from()->toDateString();

        // approved cheklar; kassirda faqat o'zinikilar
        $sales = function () use ($all, $user) {
            $q = DB::table('sales')->where('sales.status', 'approved');

            return $all ? $q : $q->where('sales.created_by', $user->id);
        };

        $sold = $sales()->where('sales.type', 'sale')->whereDate('sales.sold_at', $today)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(sales.total),0) t, COALESCE(SUM(sales.debt_amount),0) d, COALESCE(SUM(sales.discount),0) disc')->first();
        $ret = $sales()->where('sales.type', 'return')->whereDate('sales.sold_at', $today)->selectRaw('COUNT(*) c, COALESCE(SUM(sales.total),0) t')->first();
        $avg = (int) $sold->c ? ((float) $sold->t) / (int) $sold->c : 0;

        $shift = DB::table('cashbox_shifts')->where('user_id', $user->id)->where('status', 'open')->first();
        $shiftSales = null;
        if ($shift) {
            $shiftSales = DB::table('sales')->where('shift_id', $shift->id)->where('status', 'approved')->where('type', 'sale')
                ->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t')->first();
        }

        $daily = $sales()->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw('DATE(sales.sold_at) d, COALESCE(SUM(sales.total),0) value')->groupBy(DB::raw('DATE(sales.sold_at)'))->get();

        $hrs = $sales()->where('sales.type', 'sale')->whereDate('sales.sold_at', $today)
            ->selectRaw($this->hourExpr('sales.sold_at').' h, COUNT(*) value')->groupBy(DB::raw($this->hourExpr('sales.sold_at')))->get()->keyBy(fn ($r) => (int) $r->h);
        $hourRows = [];
        for ($h = 0; $h < 24; $h++) {
            $hourRows[] = ['label' => sprintf('%02d', $h), 'value' => (float) ($hrs[$h]->value ?? 0)];
        }

        $payQ = DB::table('payments')->where('direction', 'in')->whereDate('created_at', $today);
        if (! $all) {
            $payQ->where('user_id', $user->id);
        }
        $methods = $payQ->selectRaw('method, COALESCE(SUM(amount),0) total')->groupBy('method')->orderByDesc('total')->get();

        $topQ = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', $today);
        if (! $all) {
            $topQ->where('sales.created_by', $user->id);
        }
        $top = $topQ->selectRaw('products.name, COALESCE(SUM(sale_items.net_total),0) total')->groupBy('products.id', 'products.name')->orderByDesc('total')->limit(8)->get();

        $cashiers = null;
        if ($all) {
            $cashiers = DB::table('sales')->join('users', 'users.id', '=', 'sales.created_by')->where('sales.status', 'approved')->where('sales.type', 'sale')
                ->whereDate('sales.sold_at', $today)->selectRaw('users.name, COALESCE(SUM(sales.total),0) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        }

        $last = $sales()->where('sales.type', 'sale')->orderByDesc('sales.sold_at')->limit(7)->get(['sales.number', 'sales.sold_at', 'sales.total']);

        return $this->respond($all ? 'Savdo oynasi statistikasi' : 'Mening savdolarim', [
            $this->kpi('Bugungi savdo', $this->m($sold->t), 'money', ((int) $sold->c).' ta chek', 'blue', 'shopping'),
            $this->kpi('Joriy smena', $this->m($shiftSales->t ?? 0), 'money', $shift ? ((int) $shiftSales->c).' ta chek · '.substr((string) $shift->opened_at, 11, 5).' dan' : 'smena ochilmagan', $shift ? 'emerald' : 'amber', 'clock'),
            $this->kpi('O‘rtacha chek', $this->m($avg), 'money', 'bugun', 'violet', 'receipt'),
            $this->kpi('Berilgan chegirma', $this->m($sold->disc), 'money', 'bugun', 'cyan', 'tag'),
            $this->kpi('Qaytarishlar', $this->m($ret->t), 'money', ((int) $ret->c).' ta bugun', 'rose', 'alert'),
            $this->kpi('Qarzga berilgan', $this->m($sold->d), 'money', 'bugun', 'orange', 'wallet'),
        ], [
            $this->chart('area', $all ? 'Kunlik savdo' : 'Mening kunlik savdom', $this->dailySeries($daily, ['value']), ['format' => 'money', 'wide' => true, 'hint' => 'So‘nggi 30 kun, tasdiqlangan sotuvlar']),
            $this->chart('donut', 'To‘lov usullari (bugun)', $this->lv($methods, 'method', 'total', fn ($k) => self::METHODS[$k] ?? $this->nice($k)), ['format' => 'money']),
            $this->chart('bars', 'Bugungi faollik', $hourRows, ['format' => 'int', 'wide' => true, 'hint' => 'Soatlar bo‘yicha cheklar soni', 'color' => '#8b5cf6']),
            $this->chart('hbar', 'Bugungi top mahsulotlar', $this->lv($top), ['format' => 'money', 'color' => '#10b981']),
            $cashiers ? $this->chart('hbar', 'Kassirlar (bugun)', $this->lv($cashiers), ['format' => 'money', 'color' => '#06b6d4']) : null,
            $this->chart('table', 'Oxirgi cheklar', collect($last)->map(fn ($r) => ['name' => $r->number, 'at' => $this->stamp($r->sold_at), 'total' => (float) $r->total])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Chek'], ['key' => 'at', 'label' => 'Vaqt'], ['key' => 'total', 'label' => 'Summa', 'format' => 'money']],
            ]),
        ], 'Bugun va so‘nggi '.self::DAYS.' kun');
    }

    // ------------------------------------------------------------------ XAVFSIZLIK

    public function security(Request $request)
    {
        $user = $request->user();

        return $user->can('users.view') ? $this->securityAll() : $this->securityOwn($user);
    }

    private function securityOwn($user)
    {
        $from = $this->from()->toDateString();
        $logs = fn () => DB::table('audit_logs')->where('user_id', $user->id)->where('action', 'like', 'auth.%')->whereDate('created_at', '>=', $from);

        $row = DB::table('users')->where('id', $user->id)->first(['two_factor_confirmed_at', 'last_login_at', 'last_login_ip']);
        $sessions = DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->count();
        $events = $logs()->count();
        $blocked = $logs()->where('action', 'auth.login_blocked')->count();
        $pwd = DB::table('audit_logs')->where('user_id', $user->id)->where('action', 'auth.password_changed')->max('created_at');
        $pwdDays = $pwd ? (int) Carbon::parse($pwd)->diffInDays(Carbon::now()) : null;
        $twofa = ! empty($row->two_factor_confirmed_at);

        $daily = $logs()->selectRaw('DATE(created_at) d, COUNT(*) value')->groupBy(DB::raw('DATE(created_at)'))->get();
        $byAction = $logs()->selectRaw('action, COUNT(*) total')->groupBy('action')->orderByDesc('total')->limit(8)->get();
        $recent = DB::table('audit_logs')->where('user_id', $user->id)->where('action', 'like', 'auth.%')->orderByDesc('created_at')->limit(8)->get(['action', 'result', 'ip_address', 'created_at']);

        return $this->respond('Mening hisobim xavfsizligi', [
            $this->kpi('Ikki bosqichli tasdiq (2FA)', $twofa ? 'Yoqilgan' : 'Yoqilmagan', 'text', $twofa ? 'hisob himoyalangan' : 'yoqish tavsiya etiladi', $twofa ? 'emerald' : 'rose', 'alert'),
            $this->kpi('Oxirgi kirish', $row && $row->last_login_at ? $this->stamp($row->last_login_at) : '—', 'text', $row && $row->last_login_ip ? 'IP: '.$row->last_login_ip : null, 'blue', 'clock'),
            $this->kpi('Faol sessiyalar', $sessions, 'int', 'ochiq qurilmalar', 'violet', 'activity'),
            $this->kpi('Hisob hodisalari', $events, 'int', 'so‘nggi 30 kun', 'cyan', 'file'),
            $this->kpi('Parol oxirgi marta', $pwd ? Carbon::parse($pwd)->format('d.m.Y') : 'o‘zgartirilmagan', 'text', $pwdDays !== null ? $pwdDays.' kun oldin' : 'parolni yangilash tavsiya etiladi', $pwdDays !== null && $pwdDays > 180 ? 'amber' : 'indigo', 'tag'),
            $this->kpi('Bloklangan urinishlar', $blocked, 'int', 'so‘nggi 30 kun', $blocked ? 'rose' : 'slate', 'alert'),
        ], [
            $this->chart('bars', 'Hisob faolligi', $this->dailySeries($daily, ['value']), ['format' => 'int', 'wide' => true, 'hint' => 'Kirish, chiqish va xavfsizlik hodisalari', 'color' => '#2563eb']),
            $this->chart('donut', 'Hodisa turlari', $this->lv($byAction, 'action', 'total', fn ($k) => self::AUTH_EVENTS[$k] ?? $this->nice($k)), ['format' => 'int']),
            $this->chart('table', 'Oxirgi hodisalar', collect($recent)->map(fn ($r) => [
                'name' => (self::AUTH_EVENTS[$r->action] ?? $this->nice($r->action)).($r->result === 'failed' ? ' (xato)' : ''),
                'at' => $this->stamp($r->created_at), 'ip' => $r->ip_address ?: '—',
            ])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Hodisa'], ['key' => 'at', 'label' => 'Vaqt'], ['key' => 'ip', 'label' => 'IP']],
            ]),
        ]);
    }

    private function securityAll()
    {
        $from = $this->from()->toDateString();
        $logs = fn () => DB::table('audit_logs')->where('action', 'like', 'auth.%')->whereDate('created_at', '>=', $from);

        $active = DB::table('users')->where('status', 'active')->count();
        $twofa = DB::table('users')->where('status', 'active')->whereNotNull('two_factor_confirmed_at')->count();
        $failed = $logs()->where('action', 'auth.login_failed')->count();
        $blocked = $logs()->where('action', 'auth.login_blocked')->count();
        $tokens = DB::table('personal_access_tokens')->count();
        $hasReq = Schema::hasTable('two_factor_recovery_requests');
        $pending = $hasReq ? DB::table('two_factor_recovery_requests')->where('status', 'pending')->count() : 0;

        $daily = $logs()->whereIn('action', ['auth.login', 'auth.login_2fa', 'auth.login_failed', 'auth.login_blocked'])
            ->selectRaw("DATE(created_at) d, SUM(CASE WHEN action IN ('auth.login','auth.login_2fa') THEN 1 ELSE 0 END) ok, SUM(CASE WHEN action IN ('auth.login_failed','auth.login_blocked') THEN 1 ELSE 0 END) fail")
            ->groupBy(DB::raw('DATE(created_at)'))->get();
        $byAction = $logs()->selectRaw('action, COUNT(*) total')->groupBy('action')->orderByDesc('total')->limit(8)->get();
        $ips = $logs()->where('action', 'auth.login_failed')->selectRaw('ip_address, COUNT(*) total')->groupBy('ip_address')->orderByDesc('total')->limit(6)->get();
        $requests = $hasReq ? DB::table('two_factor_recovery_requests')->selectRaw('status, COUNT(*) total')->groupBy('status')->orderByDesc('total')->get() : collect();
        $recent = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.action', 'like', 'auth.%')
            ->orderByDesc('audit_logs.created_at')->limit(8)->get(['audit_logs.action', 'audit_logs.result', 'audit_logs.ip_address', 'audit_logs.created_at', 'users.name as uname']);
        $reqLabels = ['pending' => 'Kutilmoqda', 'approved' => 'Tasdiqlangan', 'rejected' => 'Rad etilgan', 'completed' => 'Bajarilgan', 'expired' => 'Muddati o‘tgan'];

        return $this->respond('Xavfsizlik statistikasi', [
            $this->kpi('2FA qamrovi', $active ? round($twofa * 100 / $active, 1) : 0, 'pct', $twofa.' / '.$active.' faol hisob', 'emerald', 'alert'),
            $this->kpi('2FA yoqilmagan', max(0, $active - $twofa), 'int', 'faol hisoblar', $active - $twofa > 0 ? 'amber' : 'slate', 'users'),
            $this->kpi('Noto‘g‘ri kirishlar', $failed, 'int', 'so‘nggi 30 kun', $failed ? 'rose' : 'slate', 'alert'),
            $this->kpi('Bloklangan urinishlar', $blocked, 'int', 'so‘nggi 30 kun', $blocked ? 'orange' : 'slate', 'clock'),
            $this->kpi('Faol sessiyalar', $tokens, 'int', 'barcha foydalanuvchilar', 'violet', 'activity'),
            $this->kpi('2FA tiklash so‘rovlari', $pending, 'int', 'ko‘rib chiqilmagan', $pending ? 'amber' : 'slate', 'file'),
        ], [
            $this->chart('bars', 'Kirishlar dinamikasi', $this->dailySeries($daily, ['ok', 'fail']), [
                'format' => 'int', 'wide' => true, 'hint' => 'Muvaffaqiyatli va muvaffaqiyatsiz kirishlar',
                'series' => [['key' => 'ok', 'name' => 'Muvaffaqiyatli', 'color' => '#10b981'], ['key' => 'fail', 'name' => 'Muvaffaqiyatsiz', 'color' => '#f43f5e']],
            ]),
            $this->chart('donut', '2FA holati', [['label' => 'Yoqilgan', 'value' => (float) $twofa], ['label' => 'Yoqilmagan', 'value' => (float) max(0, $active - $twofa)]], ['format' => 'int', 'hint' => 'Faol hisoblar']),
            $this->chart('donut', 'Hodisa turlari', $this->lv($byAction, 'action', 'total', fn ($k) => self::AUTH_EVENTS[$k] ?? $this->nice($k)), ['format' => 'int']),
            $this->chart('hbar', 'Noto‘g‘ri urinishlar — IP manzillar', $this->lv($ips, 'ip_address', 'total', fn ($k) => $k ?: '—'), ['format' => 'int', 'hint' => '30 kun', 'color' => '#f43f5e']),
            $this->chart('donut', '2FA tiklash so‘rovlari', $this->lv($requests, 'status', 'total', fn ($k) => $reqLabels[$k] ?? $this->nice($k)), ['format' => 'int']),
            $this->chart('table', 'Oxirgi hodisalar', collect($recent)->map(fn ($r) => [
                'name' => (self::AUTH_EVENTS[$r->action] ?? $this->nice($r->action)).($r->result === 'failed' ? ' (xato)' : '').' · '.($r->uname ?: '—'),
                'at' => $this->stamp($r->created_at), 'ip' => $r->ip_address ?: '—',
            ])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Hodisa'], ['key' => 'at', 'label' => 'Vaqt'], ['key' => 'ip', 'label' => 'IP']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ SESSIYALAR

    public function sessions(Request $request)
    {
        $user = $request->user();
        $all = $user->can('users.view');
        $from = $this->from()->toDateString();
        $now = Carbon::now();
        $d1 = $now->copy()->subDay();
        $d7 = $now->copy()->subDays(7);
        $d30 = $now->copy()->subDays(30);

        $tok = function () use ($all, $user) {
            $q = DB::table('personal_access_tokens');

            return $all ? $q : $q->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id);
        };

        $total = $tok()->count();
        $active24 = $tok()->where('last_used_at', '>=', $d1)->count();
        $stale7 = $tok()->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $d7))->count();
        $created30 = $tok()->whereDate('created_at', '>=', $from)->count();
        $buckets = [
            ['label' => '24 soat ichida', 'value' => (float) $active24],
            ['label' => '1–7 kun oldin', 'value' => (float) $tok()->where('last_used_at', '<', $d1)->where('last_used_at', '>=', $d7)->count()],
            ['label' => '7–30 kun oldin', 'value' => (float) $tok()->where('last_used_at', '<', $d7)->where('last_used_at', '>=', $d30)->count()],
            ['label' => '30+ kun / ishlatilmagan', 'value' => (float) $tok()->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $d30))->count()],
        ];
        $daily = $tok()->whereDate('created_at', '>=', $from)->selectRaw('DATE(created_at) d, COUNT(*) value')->groupBy(DB::raw('DATE(created_at)'))->get();
        $staleNote = $stale7 ? 'bekor qilish tavsiya etiladi' : 'hammasi faol';

        if (! $all) {
            $oldest = $tok()->min('created_at');
            $current = $user->currentAccessToken();
            $list = $tok()->orderByRaw('last_used_at IS NULL, last_used_at DESC')->limit(7)->get(['id', 'name', 'last_used_at', 'created_at']);

            return $this->respond('Mening sessiyalarim', [
                $this->kpi('Faol sessiyalar', $total, 'int', 'ochiq qurilmalar', 'blue', 'activity'),
                $this->kpi('So‘nggi 24 soatda faol', $active24, 'int', null, 'emerald', 'trend'),
                $this->kpi('Faolsiz (7+ kun)', $stale7, 'int', $staleNote, $stale7 ? 'amber' : 'slate', 'clock'),
                $this->kpi('Eng eski sessiya', $oldest ? ((int) Carbon::parse($oldest)->diffInDays($now)).' kun' : '—', 'text', $oldest ? 'ochilgan: '.Carbon::parse($oldest)->format('d.m.Y') : null, 'violet', 'tag'),
                $this->kpi('Joriy sessiya', $current && $current->created_at ? Carbon::parse($current->created_at)->format('d.m H:i') : '—', 'text', 'ochilgan vaqti', 'cyan', 'clock'),
                $this->kpi('Yangi sessiyalar', $created30, 'int', 'so‘nggi 30 kun', 'indigo', 'file'),
            ], [
                $this->chart('bars', 'Sessiyalar qachon ochilgan', $this->dailySeries($daily, ['value']), ['format' => 'int', 'wide' => true, 'hint' => 'Hozir faol sessiyalar bo‘yicha', 'color' => '#2563eb']),
                $this->chart('donut', 'Oxirgi faollik', $buckets, ['format' => 'int']),
                $this->chart('table', 'Sessiyalar', collect($list)->map(fn ($r) => [
                    'name' => '#'.$r->id.' · '.$r->name, 'last' => $r->last_used_at ? $this->stamp($r->last_used_at) : 'ishlatilmagan', 'created' => $this->stamp($r->created_at),
                ])->all(), [
                    'columns' => [['key' => 'name', 'label' => 'Sessiya'], ['key' => 'last', 'label' => 'Oxirgi faollik'], ['key' => 'created', 'label' => 'Ochilgan']],
                ]),
            ], 'Joriy holat');
        }

        $users = $tok()->distinct()->count('tokenable_id');
        $multi = DB::table('personal_access_tokens')->select('tokenable_id')->groupBy('tokenable_id')->havingRaw('COUNT(*) >= 3')->get()->count();
        $top = DB::table('personal_access_tokens as t')->join('users', 'users.id', '=', 't.tokenable_id')->selectRaw('users.name, COUNT(*) total')
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        $idle = DB::table('personal_access_tokens as t')->join('users', 'users.id', '=', 't.tokenable_id')
            ->orderByRaw('t.last_used_at IS NULL DESC, t.last_used_at ASC')->limit(7)->get(['users.name as uname', 't.last_used_at', 't.created_at']);

        return $this->respond('Sessiyalar statistikasi', [
            $this->kpi('Faol sessiyalar', $total, 'int', 'barcha foydalanuvchilar', 'blue', 'activity'),
            $this->kpi('Sessiyali foydalanuvchilar', $users, 'int', 'kamida bitta ochiq sessiya', 'violet', 'users'),
            $this->kpi('So‘nggi 24 soatda faol', $active24, 'int', null, 'emerald', 'trend'),
            $this->kpi('Faolsiz (7+ kun)', $stale7, 'int', $staleNote, $stale7 ? 'amber' : 'slate', 'clock'),
            $this->kpi('3+ sessiyali hisoblar', $multi, 'int', 'bir nechta qurilma', $multi ? 'orange' : 'slate', 'alert'),
            $this->kpi('Yangi sessiyalar', $created30, 'int', 'so‘nggi 30 kun', 'cyan', 'file'),
        ], [
            $this->chart('bars', 'Sessiyalar qachon ochilgan', $this->dailySeries($daily, ['value']), ['format' => 'int', 'wide' => true, 'hint' => 'Hozir faol sessiyalar bo‘yicha', 'color' => '#2563eb']),
            $this->chart('donut', 'Oxirgi faollik', $buckets, ['format' => 'int']),
            $this->chart('hbar', 'Eng ko‘p sessiyali foydalanuvchilar', $this->lv($top), ['format' => 'int', 'color' => '#8b5cf6']),
            $this->chart('table', 'Eng uzoq faolsiz sessiyalar', collect($idle)->map(fn ($r) => [
                'name' => $r->uname, 'last' => $r->last_used_at ? $this->stamp($r->last_used_at) : 'ishlatilmagan', 'created' => $this->stamp($r->created_at),
            ])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Foydalanuvchi'], ['key' => 'last', 'label' => 'Oxirgi faollik'], ['key' => 'created', 'label' => 'Ochilgan']],
            ]),
        ], 'Joriy holat');
    }

    // ------------------------------------------------------------------ TELEGRAM

    public function telegram(Request $request)
    {
        abort_unless($request->user()->can('users.view'), 403);
        $from = $this->from()->toDateString();

        $recipients = DB::table('telegram_recipients')->orderBy('id')->get();
        $total = $recipients->count();
        $activeRows = $recipients->where('is_active', true);
        $active = $activeRows->count();
        $linked = $recipients->whereNotNull('user_id')->count();

        // Voqea turi bo'yicha faol obunachilar (event_types bo'sh bo'lsa — hamma voqeaga obuna)
        $subs = array_fill_keys(array_keys(self::TG_EVENTS), 0);
        $allEvents = 0;
        foreach ($activeRows as $r) {
            $ev = is_string($r->event_types) ? json_decode($r->event_types, true) : $r->event_types;
            if (empty($ev) || ! is_array($ev)) {
                $allEvents++;
                foreach ($subs as $k => $_) {
                    $subs[$k]++;
                }

                continue;
            }
            foreach ($ev as $k) {
                if (isset($subs[$k])) {
                    $subs[$k]++;
                }
            }
        }
        $eventRows = [];
        foreach (self::TG_EVENTS as $k => $label) {
            $eventRows[] = ['label' => $label, 'value' => (float) $subs[$k]];
        }

        $configured = (string) config('telegram.bot_token') !== '';
        $pending = Schema::hasTable('jobs') ? DB::table('jobs')->where('payload', 'like', '%SendTelegramAlert%')->count() : 0;
        $hasFailed = Schema::hasTable('failed_jobs');
        $failed = $hasFailed ? DB::table('failed_jobs')->where('payload', 'like', '%SendTelegramAlert%')->whereDate('failed_at', '>=', $from)->count() : 0;
        $failedDaily = $hasFailed
            ? DB::table('failed_jobs')->where('payload', 'like', '%SendTelegramAlert%')->whereDate('failed_at', '>=', $from)->selectRaw('DATE(failed_at) d, COUNT(*) value')->groupBy(DB::raw('DATE(failed_at)'))->get()
            : collect();

        $list = $recipients->take(7)->map(function ($r) {
            $ev = is_string($r->event_types) ? json_decode($r->event_types, true) : $r->event_types;

            return [
                'name' => $r->label ?: 'Nomsiz chat',
                'events' => empty($ev) || ! is_array($ev) ? 'Barchasi' : count($ev).' ta',
                'state' => $r->is_active ? 'Faol' : 'O‘chirilgan',
            ];
        })->values()->all();

        return $this->respond('Telegram bildirishnomalari statistikasi', [
            $this->kpi('Qabul qiluvchilar', $total, 'int', $active.' tasi faol', 'blue', 'users'),
            $this->kpi('Bot holati', $configured ? 'Ulangan' : 'Sozlanmagan', 'text', $configured ? 'token serverda sozlangan' : 'ERP_TELEGRAM_BOT_TOKEN kerak', $configured ? 'emerald' : 'rose', 'alert'),
            $this->kpi('Navbatdagi xabarlar', $pending, 'int', 'yuborilishi kutilmoqda', $pending ? 'amber' : 'slate', 'clock'),
            $this->kpi('Yuborilmagan xabarlar', $failed, 'int', 'so‘nggi 30 kun', $failed ? 'rose' : 'slate', 'alert'),
            $this->kpi('Hamma voqeaga obuna', $allEvents, 'int', 'faol qabul qiluvchilar', 'violet', 'activity'),
            $this->kpi('Xodimga bog‘langan', $linked, 'int', 'jami '.$total.' tadan', 'cyan', 'file'),
        ], [
            $this->chart('donut', 'Qabul qiluvchilar holati', [['label' => 'Faol', 'value' => (float) $active], ['label' => 'O‘chirilgan', 'value' => (float) max(0, $total - $active)]], ['format' => 'int']),
            $this->chart('hbar', 'Voqealar bo‘yicha obunachilar', $eventRows, ['format' => 'int', 'hint' => 'Faol qabul qiluvchilar', 'color' => '#06b6d4']),
            $this->chart('bars', 'Yuborilmagan xabarlar', $this->dailySeries($failedDaily, ['value']), ['format' => 'int', 'wide' => true, 'hint' => 'Kunlik; bo‘sh bo‘lsa — muammo yo‘q', 'color' => '#f43f5e']),
            $this->chart('table', 'Qabul qiluvchilar', $list, [
                'columns' => [['key' => 'name', 'label' => 'Chat'], ['key' => 'events', 'label' => 'Voqealar'], ['key' => 'state', 'label' => 'Holat']],
            ]),
        ], 'Joriy holat');
    }
}
