<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PartyLedgerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sahifalar tepasidagi "Statistika" paneli uchun ma'lumotlar.
 * Hamma endpoint bir xil tuzilmada javob beradi: { title, period, kpis[], charts[] } —
 * frontend (InsightsPanel) uni umumiy tarzda chizadi, shuning uchun yangi sahifa uchun faqat shu yerga metod qo'shiladi.
 */
class StatsController extends Controller
{
    private const DAYS = 30;

    private const METHODS = ['cash' => 'Naqd pul', 'card' => 'Bank kartasi', 'click' => 'Click', 'payme' => 'Payme', 'transfer' => 'Bank o‘tkazmasi'];
    private const MOVES = [
        'opening' => 'Boshlang‘ich qoldiq', 'sale' => 'Sotuv', 'return' => 'Qaytarish', 'purchase' => 'Xarid', 'purchase_return' => 'Xaridni qaytarish',
        'transfer' => 'O‘tkazma (chiqim)', 'transfer_in' => 'O‘tkazma (kirim)', 'issue' => 'Chiqim hujjati', 'inventory_gain' => 'Inventarizatsiya (ortiqcha)',
        'inventory_loss' => 'Inventarizatsiya (kamomad)', 'reversal' => 'Bekor qilish', 'adjustment' => 'Tuzatish',
    ];
    private const STATUS = ['draft' => 'Qoralama', 'approved' => 'Tasdiqlangan', 'cancelled' => 'Bekor qilingan'];
    private const CASH_CATS = [
        'manual_in' => 'Kassaga kirim', 'manual_out' => 'Kassadan chiqim', 'opening_adjustment' => 'Boshlang‘ich qoldiq tuzatishi', 'shift_difference' => 'Smena farqi',
        'sale' => 'Savdo', 'sale_return' => 'Sotuvni qaytarish', 'sale_cancel' => 'Savdo bekor qilindi', 'debt_payment' => 'Mijoz qarzi to‘lovi',
        'debt_payment_cancel' => 'Mijoz to‘lovi bekor qilindi', 'supplier_payment' => 'Yetkazib beruvchiga to‘lov', 'supplier_payment_cancel' => 'Yetkazib beruvchi to‘lovi bekor qilindi',
        'expense' => 'Xarajat', 'bank_transfer' => 'Bank o‘tkazmasi',
    ];
    private const EXPENSE_METHODS = ['cash' => 'Naqd pul', 'bank' => 'Bank orqali'];

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

    /** [{label:'dd.mm', value}] — bo'sh kunlar 0 bilan to'ldiriladi. */
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

    // ------------------------------------------------------------------ SOTUVLAR TARIXI

    public function sales(Request $request)
    {
        $from = $this->from()->toDateString();
        $to = Carbon::today()->toDateString();
        $base = fn () => DB::table('sales')->whereDate('sold_at', '>=', $from)->whereDate('sold_at', '<=', $to);
        $sold = $base()->where('status', 'approved')->where('type', 'sale')->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t, COALESCE(SUM(debt_amount),0) d')->first();
        $ret = $base()->where('status', 'approved')->where('type', 'return')->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t')->first();
        $cancelled = $base()->where('status', 'cancelled')->count();
        $today = DB::table('sales')->where('status', 'approved')->where('type', 'sale')->whereDate('sold_at', $to)->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t')->first();
        $avg = (int) $sold->c ? ((float) $sold->t) / (int) $sold->c : 0;

        $daily = $base()->where('status', 'approved')->where('type', 'sale')->selectRaw('DATE(sold_at) d, COALESCE(SUM(total),0) value')->groupBy(DB::raw('DATE(sold_at)'))->get();
        $hours = $base()->where('status', 'approved')->where('type', 'sale')->selectRaw($this->hourExpr('sold_at').' h, COUNT(*) value')->groupBy(DB::raw($this->hourExpr('sold_at')))->get()->keyBy(fn ($r) => (int) $r->h);
        $hourRows = [];
        for ($h = 0; $h < 24; $h++) {
            $hourRows[] = ['label' => sprintf('%02d', $h), 'value' => (float) ($hours[$h]->value ?? 0)];
        }
        $methods = DB::table('payments')->where('direction', 'in')->whereDate('created_at', '>=', $from)->selectRaw('method, COALESCE(SUM(amount),0) total')->groupBy('method')->orderByDesc('total')->get();
        $cashiers = DB::table('sales')->join('users', 'users.id', '=', 'sales.created_by')->where('sales.status', 'approved')->where('sales.type', 'sale')
            ->whereDate('sales.sold_at', '>=', $from)->selectRaw('users.name, COALESCE(SUM(sales.total),0) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        $top = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw('products.name, COALESCE(SUM(sale_items.net_total),0) total')->groupBy('products.id', 'products.name')->orderByDesc('total')->limit(8)->get();
        $customers = DB::table('sales')->join('customers', 'customers.id', '=', 'sales.customer_id')->where('sales.status', 'approved')->where('sales.type', 'sale')
            ->whereDate('sales.sold_at', '>=', $from)->selectRaw('customers.name, COUNT(*) count, COALESCE(SUM(sales.total),0) total')
            ->groupBy('customers.id', 'customers.name')->orderByDesc('total')->limit(7)->get();

        return $this->respond('Savdo statistikasi', [
            $this->kpi('Bugungi savdo', $this->m($today->t), 'money', ((int) $today->c).' ta chek', 'blue', 'shopping'),
            $this->kpi('30 kunlik savdo', $this->m($sold->t), 'money', ((int) $sold->c).' ta chek', 'emerald', 'trend'),
            $this->kpi('O‘rtacha chek', $this->m($avg), 'money', null, 'violet', 'receipt'),
            $this->kpi('Qaytarishlar', $this->m($ret->t), 'money', ((int) $ret->c).' ta', 'rose', 'alert'),
            $this->kpi('Qarzga berilgan', $this->m($sold->d), 'money', null, 'amber', 'wallet'),
            $this->kpi('Bekor qilingan', $cancelled, 'int', 'cheklar', 'slate', 'file'),
        ], [
            $this->chart('area', 'Kunlik savdo', $this->dailySeries($daily, ['value']), ['format' => 'money', 'wide' => true, 'hint' => 'Tasdiqlangan sotuvlar summasi']),
            $this->chart('donut', 'To‘lov usullari', $this->lv($methods, 'method', 'total', fn ($k) => self::METHODS[$k] ?? $this->nice($k)), ['format' => 'money']),
            $this->chart('bars', 'Kun davomida faollik', $hourRows, ['format' => 'int', 'wide' => true, 'hint' => 'Soatlar bo‘yicha cheklar soni', 'color' => '#8b5cf6']),
            $this->chart('hbar', 'Kassirlar reytingi', $this->lv($cashiers), ['format' => 'money', 'color' => '#06b6d4']),
            $this->chart('hbar', 'Top mahsulotlar', $this->lv($top), ['format' => 'money', 'color' => '#10b981']),
            $this->chart('table', 'Top mijozlar', collect($customers)->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count, 'total' => (float) $r->total])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Mijoz'], ['key' => 'count', 'label' => 'Xaridlar', 'format' => 'int'], ['key' => 'total', 'label' => 'Summa', 'format' => 'money']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ MAHSULOTLAR

    public function products(Request $request)
    {
        $from = $this->from()->toDateString();
        $live = fn () => DB::table('products')->whereNull('products.deleted_at');
        $active = $live()->where('is_active', true)->count();
        $inactive = $live()->where('is_active', false)->count();
        $noBarcode = $live()->where('is_active', true)->where(fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''))->count();
        $margin = $live()->where('is_active', true)->where('purchase_price', '>', 0)->selectRaw('AVG((sale_price - purchase_price) * 100.0 / purchase_price) m')->first()->m ?? null;
        $low = DB::query()->fromSub(
            DB::table('products')->leftJoin('warehouse_stocks', 'warehouse_stocks.product_id', '=', 'products.id')->whereNull('products.deleted_at')->where('products.is_active', true)
                ->selectRaw('products.id')->groupBy('products.id', 'products.min_stock')->havingRaw('COALESCE(SUM(warehouse_stocks.quantity),0) <= products.min_stock'), 'l'
        )->count();

        $cats = $live()->leftJoin('categories', 'categories.id', '=', 'products.category_id')->where('products.is_active', true)
            ->selectRaw('COALESCE(categories.name, \'Kategoriyasiz\') name, COUNT(*) total')->groupBy('categories.id', 'categories.name')->orderByDesc('total')->get();
        $catRows = $this->lv($cats->take(6));
        if ($cats->count() > 6) {
            $catRows[] = ['label' => 'Boshqa', 'value' => (float) $cats->slice(6)->sum('total')];
        }
        $b = $live()->where('is_active', true)->selectRaw(
            "SUM(CASE WHEN sale_price < 10000 THEN 1 ELSE 0 END) b1, SUM(CASE WHEN sale_price >= 10000 AND sale_price < 50000 THEN 1 ELSE 0 END) b2,
             SUM(CASE WHEN sale_price >= 50000 AND sale_price < 100000 THEN 1 ELSE 0 END) b3, SUM(CASE WHEN sale_price >= 100000 AND sale_price < 500000 THEN 1 ELSE 0 END) b4,
             SUM(CASE WHEN sale_price >= 500000 AND sale_price < 1000000 THEN 1 ELSE 0 END) b5, SUM(CASE WHEN sale_price >= 1000000 THEN 1 ELSE 0 END) b6"
        )->first();
        $buckets = [['10 ming gacha', $b->b1], ['10–50 ming', $b->b2], ['50–100 ming', $b->b3], ['100–500 ming', $b->b4], ['500 ming–1 mln', $b->b5], ['1 mln dan yuqori', $b->b6]];
        $topMargin = $live()->where('is_active', true)->where('purchase_price', '>', 0)->selectRaw('name, (sale_price - purchase_price) * 100.0 / purchase_price total')->orderByDesc('total')->limit(8)->get();
        $sold = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw('products.name, COALESCE(SUM(sale_items.quantity),0) total')->groupBy('products.id', 'products.name')->orderByDesc('total')->limit(8)->get();

        return $this->respond('Mahsulotlar statistikasi', [
            $this->kpi('Faol mahsulotlar', $active, 'int', $inactive.' ta nofaol', 'blue', 'package'),
            $this->kpi('Kam qolgan', $low, 'int', 'minimal qoldiqdan past', 'orange', 'alert'),
            $this->kpi('Shtrix-kodsiz', $noBarcode, 'int', 'faol mahsulotlar ichida', 'amber', 'tag'),
            $this->kpi('O‘rtacha ustama', $margin === null ? 0 : round((float) $margin, 1), 'pct', 'xarid narxiga nisbatan', 'emerald', 'trend'),
        ], [
            $this->chart('donut', 'Kategoriyalar bo‘yicha', $catRows, ['format' => 'int', 'hint' => 'Faol mahsulotlar soni']),
            $this->chart('bars', 'Narx oralig‘i', array_map(fn ($x) => ['label' => $x[0], 'value' => (float) $x[1]], $buckets), ['format' => 'int', 'wide' => true, 'hint' => 'Sotuv narxi bo‘yicha mahsulotlar soni', 'color' => '#6366f1']),
            $this->chart('hbar', 'Eng ko‘p sotilgan', $this->lv($sold), ['format' => 'int', 'hint' => '30 kun, miqdor bo‘yicha', 'color' => '#10b981']),
            $this->chart('hbar', 'Eng yuqori ustama', $this->lv($topMargin), ['format' => 'pct', 'hint' => 'Xarid narxiga nisbatan, %', 'color' => '#f59e0b']),
        ], 'Joriy holat');
    }

    // ------------------------------------------------------------------ QOLDIQ HARAKATI

    public function stockMovements(Request $request)
    {
        $from = $this->from()->toDateString();
        $base = fn () => DB::table('stock_movements')->whereDate('stock_movements.created_at', '>=', $from);
        $total = $base()->count();
        $in = $base()->where('quantity', '>', 0)->count();
        $out = $base()->where('quantity', '<', 0)->count();
        $adj = $base()->whereIn('type', ['inventory_gain', 'inventory_loss', 'adjustment', 'reversal'])->count();
        $daily = $base()->selectRaw('DATE(stock_movements.created_at) d, SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) incoming, SUM(CASE WHEN quantity < 0 THEN 1 ELSE 0 END) outgoing')
            ->groupBy(DB::raw('DATE(stock_movements.created_at)'))->get();
        $types = $base()->selectRaw('type, COUNT(*) total')->groupBy('type')->orderByDesc('total')->get();
        $products = $base()->join('products', 'products.id', '=', 'stock_movements.product_id')->selectRaw('products.name, COALESCE(SUM(ABS(stock_movements.quantity)),0) total')
            ->groupBy('products.id', 'products.name')->orderByDesc('total')->limit(8)->get();
        $warehouses = $base()->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')->selectRaw('warehouses.name, COUNT(*) total')
            ->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('total')->limit(8)->get();

        return $this->respond('Qoldiq harakati statistikasi', [
            $this->kpi('Jami harakatlar', $total, 'int', 'so‘nggi 30 kun', 'blue', 'activity'),
            $this->kpi('Kirimlar', $in, 'int', null, 'emerald', 'boxes'),
            $this->kpi('Chiqimlar', $out, 'int', null, 'rose', 'truck'),
            $this->kpi('Tuzatish va bekorlar', $adj, 'int', 'inventarizatsiya, tuzatish', 'amber', 'alert'),
        ], [
            $this->chart('bars', 'Kirim va chiqim', $this->dailySeries($daily, ['incoming', 'outgoing']), [
                'format' => 'int', 'wide' => true, 'hint' => 'Kunlik harakatlar soni',
                'series' => [['key' => 'incoming', 'name' => 'Kirim', 'color' => '#10b981'], ['key' => 'outgoing', 'name' => 'Chiqim', 'color' => '#f43f5e']],
            ]),
            $this->chart('donut', 'Harakat turlari', $this->lv($types, 'type', 'total', fn ($k) => self::MOVES[$k] ?? $this->nice($k)), ['format' => 'int']),
            $this->chart('hbar', 'Eng faol mahsulotlar', $this->lv($products), ['format' => 'int', 'hint' => 'Harakat hajmi bo‘yicha', 'color' => '#8b5cf6']),
            $this->chart('hbar', 'Omborlar kesimida', $this->lv($warehouses), ['format' => 'int', 'hint' => 'Harakatlar soni', 'color' => '#06b6d4']),
        ]);
    }

    // ------------------------------------------------------------------ OMBOR HUJJATLARI (xarid, qaytarish, o'tkazma, chiqim, inventarizatsiya)

    public function documents(Request $request)
    {
        $type = (string) $request->query('type');
        $titles = ['purchase' => 'Xaridlar', 'purchase_return' => 'Xaridni qaytarish', 'transfer' => 'Omborlararo o‘tkazmalar', 'issue' => 'Ombor muammolari (chiqim)', 'inventory' => 'Inventarizatsiya'];
        abort_unless(isset($titles[$type]), 404);
        $perm = in_array($type, ['purchase', 'purchase_return'], true) ? 'purchases.view' : 'warehouses.view';
        abort_unless($request->user()->can($perm), 403);
        $money = in_array($type, ['purchase', 'purchase_return'], true);
        $from = $this->from()->toDateString();
        $base = fn () => DB::table('stock_documents')->where('stock_documents.type', $type)->whereDate('stock_documents.doc_date', '>=', $from);

        $counts = $base()->selectRaw('stock_documents.status status, COUNT(*) c, COALESCE(SUM(stock_documents.total),0) t')->groupBy('stock_documents.status')->get()->keyBy('status');
        $c = fn ($s) => (int) ($counts[$s]->c ?? 0);
        $sum = (float) ($counts['approved']->t ?? 0);
        $all = $c('draft') + $c('approved') + $c('cancelled');

        $daily = $base()->where('stock_documents.status', 'approved')->selectRaw('DATE(stock_documents.doc_date) d, '.($money ? 'COALESCE(SUM(total),0)' : 'COUNT(*)').' value')->groupBy(DB::raw('DATE(stock_documents.doc_date)'))->get();
        $statuses = collect(['draft', 'approved', 'cancelled'])->map(fn ($s) => ['label' => self::STATUS[$s], 'value' => (float) $c($s)])->all();
        $wh = $base()->join('warehouses', 'warehouses.id', '=', 'stock_documents.warehouse_id')->selectRaw('warehouses.name, COUNT(*) total')->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('total')->limit(8)->get();

        $kpis = [$this->kpi('Hujjatlar', $all, 'int', 'so‘nggi 30 kun', 'blue', 'file'), $this->kpi('Tasdiqlangan', $c('approved'), 'int', null, 'emerald', 'trend'),
            $this->kpi('Qoralama', $c('draft'), 'int', 'tasdiq kutmoqda', 'amber', 'clock'), $this->kpi('Bekor qilingan', $c('cancelled'), 'int', null, 'rose', 'alert')];
        if ($money) {
            $kpis[] = $this->kpi('Tasdiqlangan summa', $this->m($sum), 'money', $c('approved') ? 'o‘rtacha '.number_format($sum / $c('approved'), 0, '.', ' ').' so‘m' : null, 'violet', 'wallet');
        }
        if ($type === 'purchase') {
            $paid = (float) $base()->where('stock_documents.status', 'approved')->sum('stock_documents.paid_amount');
            $kpis[] = $this->kpi('To‘lanmagan qism', $this->m(max(0, $sum - $paid)), 'money', 'nasiya: '.($sum > 0 ? round(max(0, $sum - $paid) * 100 / $sum) : 0).'% xarid', 'orange', 'alert');
        }
        $charts = [
            $this->chart($money ? 'area' : 'bars', $money ? 'Kunlik summa' : 'Kunlik hujjatlar', $this->dailySeries($daily, ['value']), ['format' => $money ? 'money' : 'int', 'wide' => true, 'hint' => 'Tasdiqlangan hujjatlar', 'color' => '#2563eb']),
            $this->chart('donut', 'Holatlar', $statuses, ['format' => 'int']),
            $this->chart('hbar', 'Omborlar kesimida', $this->lv($wh), ['format' => 'int', 'color' => '#06b6d4']),
        ];
        if ($money) {
            $sup = $base()->where('stock_documents.status', 'approved')->join('suppliers', 'suppliers.id', '=', 'stock_documents.supplier_id')
                ->selectRaw('suppliers.name, COALESCE(SUM(stock_documents.total),0) total')->groupBy('suppliers.id', 'suppliers.name')->orderByDesc('total')->limit(8)->get();
            $charts[] = $this->chart('hbar', $type === 'purchase' ? 'Yetkazib beruvchilar' : 'Qaytarish — yetkazib beruvchilar', $this->lv($sup), ['format' => 'money', 'color' => '#f43f5e']);
        }
        if (in_array($type, ['purchase_return', 'transfer', 'issue'], true)) {
            $items = DB::table('stock_document_items as i')->join('stock_documents', 'stock_documents.id', '=', 'i.stock_document_id')->join('products', 'products.id', '=', 'i.product_id')
                ->where('stock_documents.type', $type)->where('stock_documents.status', 'approved')->whereDate('stock_documents.doc_date', '>=', $from)
                ->selectRaw('products.name, COALESCE(SUM(i.quantity),0) total')->groupBy('products.id', 'products.name')->orderByDesc('total')->limit(8)->get();
            $charts[] = $this->chart('hbar', 'Eng ko‘p mahsulotlar', $this->lv($items), ['format' => 'int', 'hint' => '30 kun, miqdor bo‘yicha', 'color' => '#10b981']);
        }
        if ($type === 'transfer') {
            $routes = $base()->where('stock_documents.status', 'approved')
                ->join('warehouses as wa', 'wa.id', '=', 'stock_documents.warehouse_id')->join('warehouses as wb', 'wb.id', '=', 'stock_documents.to_warehouse_id')
                ->selectRaw("wa.name || ' → ' || wb.name AS route, COUNT(*) total")->groupBy('wa.id', 'wa.name', 'wb.id', 'wb.name')->orderByDesc('total')->limit(8)->get();
            $charts[] = $this->chart('hbar', 'Eng faol yo‘nalishlar', $this->lv($routes, 'route', 'total'), ['format' => 'int', 'hint' => 'Qaysi ombordan qaysi omborga', 'color' => '#8b5cf6']);
        }
        if ($type === 'purchase') {
            $pay = $base()->where('stock_documents.status', 'approved')->selectRaw('stock_documents.payment_method pm, COALESCE(SUM(stock_documents.total),0) total')
                ->groupBy('stock_documents.payment_method')->orderByDesc('total')->get();
            $charts[] = $this->chart('donut', 'To‘lov usullari', $this->lv($pay, 'pm', 'total', fn ($k) => $k ? (self::METHODS[$k] ?? $this->nice($k)) : 'Nasiya / ko‘rsatilmagan'), ['format' => 'money']);
            $prod = DB::table('stock_document_items as i')->join('stock_documents', 'stock_documents.id', '=', 'i.stock_document_id')->join('products', 'products.id', '=', 'i.product_id')
                ->where('stock_documents.type', 'purchase')->where('stock_documents.status', 'approved')->whereDate('stock_documents.doc_date', '>=', $from)
                ->selectRaw('products.name, COALESCE(SUM(i.quantity * COALESCE(i.price, 0)),0) total')->groupBy('products.id', 'products.name')->orderByDesc('total')->limit(8)->get();
            $charts[] = $this->chart('hbar', 'Eng ko‘p xarid qilingan mahsulotlar', $this->lv($prod), ['format' => 'money', 'hint' => '30 kun, xarid summasi', 'color' => '#10b981']);
            $buyers = $base()->where('stock_documents.status', 'approved')->join('users', 'users.id', '=', 'stock_documents.created_by')
                ->selectRaw('users.name, COUNT(*) count, COALESCE(SUM(stock_documents.total),0) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(6)->get();
            $charts[] = $this->chart('table', 'Xarid qiluvchilar', collect($buyers)->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count, 'total' => (float) $r->total])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Xodim'], ['key' => 'count', 'label' => 'Hujjat', 'format' => 'int'], ['key' => 'total', 'label' => 'Summa', 'format' => 'money']],
            ]);
        }
        if ($type === 'issue') {
            $reasons = $base()->selectRaw('reason, COUNT(*) total')->groupBy('reason')->orderByDesc('total')->get();
            $charts[] = $this->chart('donut', 'Sabablar', $this->lv($reasons, 'reason', 'total', fn ($k) => $this->nice($k)), ['format' => 'int']);
        }

        return $this->respond($titles[$type].' statistikasi', $kpis, $charts);
    }

    // ------------------------------------------------------------------ MIJOZLAR / YETKAZIB BERUVCHILAR

    public function parties(Request $request)
    {
        $kind = (string) $request->query('kind');
        abort_unless(in_array($kind, ['customer', 'supplier'], true), 404);
        abort_unless($request->user()->can($kind === 'customer' ? 'customers.view' : 'suppliers.view'), 403);

        return $kind === 'customer' ? $this->customerStats() : $this->supplierStats();
    }

    private function customerStats()
    {
        $from = $this->from()->toDateString();
        $active = DB::table('customers')->where('is_active', true)->count();
        $inactive = DB::table('customers')->where('is_active', false)->count();
        $fresh = DB::table('customers')->whereDate('created_at', '>=', $from)->count();
        $buyers = DB::table('sales')->where('status', 'approved')->where('type', 'sale')->whereNotNull('customer_id')->whereDate('sold_at', '>=', $from)
            ->selectRaw('COUNT(DISTINCT customer_id) c, COALESCE(SUM(total),0) t')->first();

        $debt = [];
        foreach (DB::table('sales')->where('status', 'approved')->whereNotNull('customer_id')->selectRaw('customer_id, type, COALESCE(SUM(debt_amount),0) d')->groupBy('customer_id', 'type')->get() as $r) {
            $debt[$r->customer_id] = ($debt[$r->customer_id] ?? 0) + ($r->type === 'return' ? -1 : 1) * (float) $r->d;
        }
        foreach (DB::table('customer_payments')->where('status', 'approved')->selectRaw('customer_id, COALESCE(SUM(amount),0) a')->groupBy('customer_id')->get() as $r) {
            $debt[$r->customer_id] = ($debt[$r->customer_id] ?? 0) - (float) $r->a;
        }
        $debtors = array_filter($debt, fn ($v) => $v > 0.009);
        arsort($debtors);
        $topIds = array_slice(array_keys($debtors), 0, 8);
        $names = $topIds ? DB::table('customers')->whereIn('id', $topIds)->pluck('name', 'id') : collect();
        $debtRows = array_map(fn ($id) => ['label' => (string) ($names[$id] ?? ('#'.$id)), 'value' => round($debtors[$id], 2)], $topIds);

        $daily = DB::table('customers')->whereDate('created_at', '>=', $from)->selectRaw('DATE(created_at) d, COUNT(*) value')->groupBy(DB::raw('DATE(created_at)'))->get();
        $top = DB::table('sales')->join('customers', 'customers.id', '=', 'sales.customer_id')->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw('customers.name, COALESCE(SUM(sales.total),0) total')->groupBy('customers.id', 'customers.name')->orderByDesc('total')->limit(8)->get();
        $debtorCount = count($debtors);

        return $this->respond('Mijozlar statistikasi', [
            $this->kpi('Faol mijozlar', $active, 'int', $inactive.' ta nofaol', 'blue', 'users'),
            $this->kpi('Yangi mijozlar', $fresh, 'int', 'so‘nggi 30 kun', 'emerald', 'trend'),
            $this->kpi('Xarid qilganlar', (int) $buyers->c, 'int', '30 kunda', 'violet', 'shopping'),
            $this->kpi('O‘rtacha xarid', $this->m((int) $buyers->c ? ((float) $buyers->t) / (int) $buyers->c : 0), 'money', 'mijoz boshiga', 'cyan', 'wallet'),
            $this->kpi('Qarzdorlar', $debtorCount, 'int', 'jami qarz '.number_format(array_sum($debtors), 0, '.', ' ').' so‘m', 'rose', 'alert'),
        ], [
            $this->chart('bars', 'Yangi mijozlar', $this->dailySeries($daily, ['value']), ['format' => 'int', 'wide' => true, 'hint' => 'Kunlik ro‘yxatdan o‘tganlar', 'color' => '#10b981']),
            $this->chart('donut', 'Qarz holati', [['label' => 'Qarzdor', 'value' => (float) $debtorCount], ['label' => 'Qarzsiz', 'value' => (float) max(0, $active + $inactive - $debtorCount)]], ['format' => 'int']),
            $this->chart('hbar', 'Top mijozlar', $this->lv($top), ['format' => 'money', 'hint' => '30 kun, xarid summasi', 'color' => '#2563eb']),
            $this->chart('hbar', 'Eng katta qarzdorlar', $debtRows, ['format' => 'money', 'color' => '#f43f5e']),
        ]);
    }

    private function supplierStats()
    {
        $from = $this->from()->toDateString();
        $active = DB::table('suppliers')->where('is_active', true)->count();
        $inactive = DB::table('suppliers')->where('is_active', false)->count();
        $buy = fn () => DB::table('stock_documents')->where('stock_documents.type', 'purchase')->where('stock_documents.status', 'approved');
        $m30 = $buy()->whereDate('stock_documents.doc_date', '>=', $from)->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t')->first();
        $top = $buy()->join('suppliers', 'suppliers.id', '=', 'stock_documents.supplier_id')->whereDate('doc_date', '>=', $from)
            ->selectRaw('suppliers.name, COALESCE(SUM(stock_documents.total),0) total')->groupBy('suppliers.id', 'suppliers.name')->orderByDesc('total')->limit(8)->get();
        $pay = $buy()->whereDate('doc_date', '>=', $from)->selectRaw('payment_method, COALESCE(SUM(total),0) total')->groupBy('payment_method')->orderByDesc('total')->get();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $s = Carbon::today()->startOfMonth()->subMonths($i);
            $months[] = ['label' => $s->format('m.Y'), 'value' => (float) $buy()->whereDate('doc_date', '>=', $s->toDateString())->whereDate('doc_date', '<=', $s->copy()->endOfMonth()->toDateString())->sum('total')];
        }

        // Bizning qarzimiz: tizimdagi yagona formula (PartyLedgerService) bo‘yicha
        $owed = fn () => DB::query()->fromSub(
            DB::table('suppliers')->selectRaw('suppliers.id, suppliers.name, '.PartyLedgerService::SUPPLIER_DEBT_SQL.' AS debt'), 't'
        )->where('debt', '>', 0.009);
        $owedTotal = (float) $owed()->sum('debt');
        $owedCount = $owed()->count();
        $topDebt = $owed()->orderByDesc('debt')->limit(8)->get(['name', 'debt']);
        $ret30 = (float) DB::table('stock_documents')->where('type', 'purchase_return')->where('status', 'approved')->whereDate('doc_date', '>=', $from)->sum('total');
        $ranking = $buy()->join('suppliers', 'suppliers.id', '=', 'stock_documents.supplier_id')->whereDate('doc_date', '>=', $from)
            ->selectRaw('suppliers.name, COUNT(*) count, COALESCE(SUM(stock_documents.total),0) total')->groupBy('suppliers.id', 'suppliers.name')->orderByDesc('total')->limit(7)->get();

        return $this->respond('Yetkazib beruvchilar statistikasi', [
            $this->kpi('Faol yetkazib beruvchilar', $active, 'int', $inactive.' ta nofaol', 'blue', 'truck'),
            $this->kpi('30 kunlik xarid', $this->m($m30->t), 'money', ((int) $m30->c).' ta hujjat', 'emerald', 'wallet'),
            $this->kpi('O‘rtacha xarid', $this->m((int) $m30->c ? ((float) $m30->t) / (int) $m30->c : 0), 'money', 'hujjat boshiga', 'violet', 'receipt'),
            $this->kpi('Bizning qarzimiz', $this->m($owedTotal), 'money', $owedCount.' ta hamkorga', 'rose', 'alert'),
            $this->kpi('Qaytarilgan tovar', $this->m($ret30), 'money', '30 kunda', 'amber', 'boxes'),
            $this->kpi('Eng katta hamkor', $top->first()->name ?? '—', 'text', $top->first() ? number_format((float) $top->first()->total, 0, '.', ' ').' so‘m' : null, 'cyan', 'trend'),
        ], [
            $this->chart('bars', 'Oylik xaridlar', $months, ['format' => 'money', 'wide' => true, 'hint' => 'So‘nggi 6 oy', 'color' => '#6366f1']),
            $this->chart('donut', 'To‘lov usullari', $this->lv($pay, 'payment_method', 'total', fn ($k) => self::METHODS[$k] ?? $this->nice($k)), ['format' => 'money']),
            $this->chart('hbar', 'Top yetkazib beruvchilar', $this->lv($top), ['format' => 'money', 'hint' => '30 kun', 'color' => '#f43f5e']),
            $this->chart('hbar', 'Eng katta qarzlarimiz', $this->lv($topDebt, 'name', 'debt'), ['format' => 'money', 'hint' => 'Yetkazib beruvchilarga to‘lanmagan', 'color' => '#f59e0b']),
            $this->chart('table', 'Hamkorlar reytingi', collect($ranking)->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count, 'total' => (float) $r->total])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Hamkor'], ['key' => 'count', 'label' => 'Hujjat', 'format' => 'int'], ['key' => 'total', 'label' => 'Summa', 'format' => 'money']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ XARAJATLAR

    public function expenses(Request $request)
    {
        $from = $this->from()->toDateString();
        $to = Carbon::today()->toDateString();
        $prevFrom = $this->from()->subDays(self::DAYS)->toDateString();
        $approved = fn () => DB::table('expenses')->where('status', 'approved');
        $inRange = fn () => $approved()->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to);

        $cur = $inRange()->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) t, COALESCE(MAX(amount),0) mx')->first();
        $prev = (float) $approved()->whereDate('expense_date', '>=', $prevFrom)->whereDate('expense_date', '<', $from)->sum('amount');
        $today = $approved()->whereDate('expense_date', $to)->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) t')->first();
        $draft = DB::table('expenses')->where('status', 'draft')->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) t')->first();
        $cancelled = DB::table('expenses')->where('status', 'cancelled')->whereDate('expense_date', '>=', $from)->count();
        $change = $prev > 0 ? (((float) $cur->t - $prev) / $prev) * 100 : 0;
        $biggest = $inRange()->orderByDesc('amount')->first(['category']);

        $daily = $inRange()->selectRaw('DATE(expense_date) d, COALESCE(SUM(amount),0) value')->groupBy(DB::raw('DATE(expense_date)'))->get();
        $catExpr = 'LOWER(TRIM(category))';
        $cats = $inRange()->selectRaw("$catExpr cat, COALESCE(SUM(amount),0) total")->groupBy(DB::raw($catExpr))->orderByDesc('total')->get();
        $catRows = $this->lv($cats->take(6), 'cat', 'total', fn ($k) => $this->nice($k));
        if ($cats->count() > 6) {
            $catRows[] = ['label' => 'Boshqa', 'value' => (float) $cats->slice(6)->sum('total')];
        }
        $methods = $inRange()->selectRaw('payment_method, COALESCE(SUM(amount),0) total')->groupBy('payment_method')->orderByDesc('total')->get();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $s = Carbon::today()->startOfMonth()->subMonths($i);
            $months[] = ['label' => $s->format('m.Y'), 'value' => (float) $approved()->whereDate('expense_date', '>=', $s->toDateString())->whereDate('expense_date', '<=', $s->copy()->endOfMonth()->toDateString())->sum('amount')];
        }
        $byUser = $inRange()->join('users', 'users.id', '=', 'expenses.created_by')->selectRaw('users.name, COALESCE(SUM(expenses.amount),0) total')
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(6)->get();
        $largest = $inRange()->orderByDesc('amount')->limit(7)->get(['number', 'category', 'expense_date', 'amount']);

        return $this->respond('Xarajatlar statistikasi', [
            $this->kpi('30 kunlik xarajat', $this->m($cur->t), 'money', ((int) $cur->c).' ta tasdiqlangan', 'rose', 'banknote'),
            $this->kpi('Oldingi 30 kunga nisbatan', round($change, 1), 'pct', $prev > 0 ? 'oldin: '.number_format($prev, 0, '.', ' ').' so‘m' : 'solishtirish uchun ma‘lumot yo‘q', $change > 0 ? 'orange' : 'emerald', 'trend'),
            $this->kpi('Bugungi xarajat', $this->m($today->t), 'money', ((int) $today->c).' ta', 'blue', 'receipt'),
            $this->kpi('Kunlik o‘rtacha', $this->m(((float) $cur->t) / self::DAYS), 'money', null, 'violet', 'activity'),
            $this->kpi('Tasdiq kutmoqda', $this->m($draft->t), 'money', ((int) $draft->c).' ta qoralama', 'amber', 'clock'),
            $this->kpi('Eng katta xarajat', $this->m($cur->mx), 'money', $biggest ? $this->nice($biggest->category) : ($cancelled.' ta bekor qilingan'), 'cyan', 'alert'),
        ], [
            $this->chart('area', 'Kunlik xarajatlar', $this->dailySeries($daily, ['value']), ['format' => 'money', 'wide' => true, 'hint' => 'Tasdiqlangan xarajatlar', 'color' => '#f43f5e']),
            $this->chart('donut', 'Kategoriyalar bo‘yicha', $catRows, ['format' => 'money']),
            $this->chart('bars', 'Oylik dinamika', $months, ['format' => 'money', 'wide' => true, 'hint' => 'So‘nggi 6 oy', 'color' => '#6366f1']),
            $this->chart('donut', 'To‘lov usuli', $this->lv($methods, 'payment_method', 'total', fn ($k) => self::EXPENSE_METHODS[$k] ?? $this->nice($k)), ['format' => 'money']),
            $this->chart('hbar', 'Xodimlar kesimida', $this->lv($byUser), ['format' => 'money', 'hint' => 'Kim qancha xarajat kiritgan', 'color' => '#06b6d4']),
            $this->chart('table', 'Eng katta xarajatlar', collect($largest)->map(fn ($r) => ['name' => $this->nice($r->category).' · '.$r->number, 'date' => substr((string) $r->expense_date, 0, 10), 'amount' => (float) $r->amount])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Xarajat'], ['key' => 'date', 'label' => 'Sana'], ['key' => 'amount', 'label' => 'Summa', 'format' => 'money']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ KASSA SMENALARI

    public function shifts(Request $request)
    {
        $from = $this->from()->toDateString();
        $base = fn () => DB::table('cashbox_shifts')->whereDate('cashbox_shifts.opened_at', '>=', $from);
        $closed = fn () => $base()->where('cashbox_shifts.status', 'closed');

        $open = DB::table('cashbox_shifts')->where('status', 'open')->count();
        $total = $base()->count();
        $diff = $closed()->selectRaw('COALESCE(SUM(CASE WHEN difference < 0 THEN -difference ELSE 0 END),0) shortage, COALESCE(SUM(CASE WHEN difference > 0 THEN difference ELSE 0 END),0) surplus')->first();
        $toReview = DB::table('cashbox_shifts')->where('status', 'closed')->whereNull('reviewed_at')->where('difference', '!=', 0)->count();
        $withDiff = $closed()->where('difference', '!=', 0)->count();
        $cleanCnt = $closed()->where(fn ($q) => $q->whereNull('difference')->orWhere('difference', 0))->count();
        $reviewed = $closed()->where('difference', '!=', 0)->whereNotNull('reviewed_at')->count();

        // O‘rtacha davomiylik — drayverdan mustaqil bo‘lishi uchun PHP da hisoblanadi
        $mins = $closed()->whereNotNull('closed_at')->get(['opened_at', 'closed_at'])
            ->map(fn ($r) => Carbon::parse($r->opened_at)->diffInMinutes(Carbon::parse($r->closed_at), true));
        $avgMin = $mins->count() ? $mins->avg() : 0;
        $avgText = $mins->count() ? floor($avgMin / 60).' soat '.str_pad((string) round(fmod($avgMin, 60)), 2, '0', STR_PAD_LEFT).' daq' : '—';

        $daily = $closed()->whereNotNull('closed_at')->selectRaw('DATE(closed_at) d, COALESCE(SUM(CASE WHEN difference < 0 THEN -difference ELSE 0 END),0) shortage, COALESCE(SUM(CASE WHEN difference > 0 THEN difference ELSE 0 END),0) surplus')
            ->groupBy(DB::raw('DATE(closed_at)'))->get();
        $status = [
            ['label' => 'Ochiq', 'value' => (float) $base()->where('cashbox_shifts.status', 'open')->count()],
            ['label' => 'Farqsiz yopilgan', 'value' => (float) $cleanCnt],
            ['label' => 'Farq bilan (ko‘rilgan)', 'value' => (float) $reviewed],
            ['label' => 'Farq bilan (kutmoqda)', 'value' => (float) max(0, $withDiff - $reviewed)],
        ];
        $bySales = DB::table('sales')->join('cashbox_shifts', 'cashbox_shifts.id', '=', 'sales.shift_id')->join('users', 'users.id', '=', 'cashbox_shifts.user_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('cashbox_shifts.opened_at', '>=', $from)
            ->selectRaw('users.name, COALESCE(SUM(sales.total),0) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        $byCashbox = $base()->join('cashboxes', 'cashboxes.id', '=', 'cashbox_shifts.cashbox_id')->selectRaw('cashboxes.name, COUNT(*) total')
            ->groupBy('cashboxes.id', 'cashboxes.name')->orderByDesc('total')->limit(8)->get();
        $hours = $base()->selectRaw($this->hourExpr('cashbox_shifts.opened_at').' h, COUNT(*) value')->groupBy(DB::raw($this->hourExpr('cashbox_shifts.opened_at')))->get()->keyBy(fn ($r) => (int) $r->h);
        $hourRows = [];
        for ($h = 0; $h < 24; $h++) {
            $hourRows[] = ['label' => sprintf('%02d', $h), 'value' => (float) ($hours[$h]->value ?? 0)];
        }
        $staff = $base()->join('users', 'users.id', '=', 'cashbox_shifts.user_id')
            ->selectRaw('users.name, COUNT(*) count, COALESCE(SUM(cashbox_shifts.difference),0) diff')->groupBy('users.id', 'users.name')->orderBy('diff')->limit(7)->get();

        return $this->respond('Kassa smenalari statistikasi', [
            $this->kpi('Ochiq smenalar', $open, 'int', 'hozirgi vaqtda', 'emerald', 'clock'),
            $this->kpi('30 kunlik smenalar', $total, 'int', $mins->count().' tasi yopilgan', 'blue', 'activity'),
            $this->kpi('O‘rtacha davomiylik', $avgText, 'text', 'yopilgan smenalar bo‘yicha', 'violet', 'clock'),
            $this->kpi('Kamomad', $this->m($diff->shortage), 'money', '30 kun, yopilganlar', 'rose', 'alert'),
            $this->kpi('Ortiqcha', $this->m($diff->surplus), 'money', '30 kun, yopilganlar', 'cyan', 'wallet'),
            $this->kpi('Tekshiruv kutmoqda', $toReview, 'int', 'farqli, ko‘rilmagan smenalar', 'amber', 'file'),
        ], [
            $this->chart('bars', 'Kamomad va ortiqcha', $this->dailySeries($daily, ['shortage', 'surplus']), [
                'format' => 'money', 'wide' => true, 'hint' => 'Smena yopilgan kunlar bo‘yicha',
                'series' => [['key' => 'shortage', 'name' => 'Kamomad', 'color' => '#f43f5e'], ['key' => 'surplus', 'name' => 'Ortiqcha', 'color' => '#06b6d4']],
            ]),
            $this->chart('donut', 'Smenalar holati', $status, ['format' => 'int']),
            $this->chart('hbar', 'Smena savdosi — kassirlar', $this->lv($bySales), ['format' => 'money', 'hint' => '30 kun, smenalarda qilingan savdo', 'color' => '#10b981']),
            $this->chart('hbar', 'Kassalar bo‘yicha smenalar', $this->lv($byCashbox), ['format' => 'int', 'color' => '#6366f1']),
            $this->chart('bars', 'Smena ochilish soatlari', $hourRows, ['format' => 'int', 'wide' => true, 'hint' => 'Qaysi soatda smena boshlanadi', 'color' => '#8b5cf6']),
            $this->chart('table', 'Kassirlar farqi', collect($staff)->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count, 'diff' => (float) $r->diff])->all(), [
                'hint' => 'Manfiy — kamomad',
                'columns' => [['key' => 'name', 'label' => 'Kassir'], ['key' => 'count', 'label' => 'Smena', 'format' => 'int'], ['key' => 'diff', 'label' => 'Sof farq', 'format' => 'money']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ KASSALAR (naqd pul daftari)

    public function cashboxes(Request $request)
    {
        $fromC = $this->from();
        $from = $fromC->toDateString();
        $tx = fn () => DB::table('cash_transactions');
        $range = fn () => $tx()->whereDate('cash_transactions.occurred_at', '>=', $from);
        $signed = "CASE WHEN direction = 'in' THEN amount ELSE -amount END";

        $balance = (float) $tx()->selectRaw("COALESCE(SUM($signed),0) b")->value('b');
        $opening = (float) $tx()->whereDate('occurred_at', '<', $from)->selectRaw("COALESCE(SUM($signed),0) b")->value('b');
        $io = $range()->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END),0) i, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END),0) o, COUNT(*) c")->first();
        $activeBoxes = DB::table('cashboxes')->where('is_active', true)->count();
        $inactiveBoxes = DB::table('cashboxes')->where('is_active', false)->count();

        $daily = $range()->selectRaw("DATE(occurred_at) d, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END),0) incoming, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END),0) outgoing")
            ->groupBy(DB::raw('DATE(occurred_at)'))->get();
        $flow = $this->dailySeries($daily, ['incoming', 'outgoing']);
        $running = $opening;
        $curve = array_map(function ($r) use (&$running) {
            $running += $r['incoming'] - $r['outgoing'];

            return ['label' => $r['label'], 'value' => round($running, 2)];
        }, $flow);

        $perBox = DB::table('cashboxes')->leftJoin('cash_transactions as t', 't.cashbox_id', '=', 'cashboxes.id')
            ->selectRaw("cashboxes.name, COALESCE(SUM(CASE WHEN t.direction = 'in' THEN t.amount WHEN t.direction = 'out' THEN -t.amount ELSE 0 END),0) total")
            ->groupBy('cashboxes.id', 'cashboxes.name')->orderByDesc('total')->limit(8)->get();
        $turnover = $range()->join('cashboxes', 'cashboxes.id', '=', 'cash_transactions.cashbox_id')->selectRaw('cashboxes.name, COALESCE(SUM(cash_transactions.amount),0) total')
            ->groupBy('cashboxes.id', 'cashboxes.name')->orderByDesc('total')->limit(8)->get();
        $cat = fn (string $dir) => $range()->where('direction', $dir)->selectRaw('category, COALESCE(SUM(amount),0) total')->groupBy('category')->orderByDesc('total')->limit(7)->get();
        $largest = $range()->join('cashboxes', 'cashboxes.id', '=', 'cash_transactions.cashbox_id')->orderByDesc('cash_transactions.amount')->limit(7)
            ->get(['cash_transactions.direction', 'cash_transactions.category', 'cash_transactions.amount', 'cash_transactions.occurred_at', 'cashboxes.name as box']);
        $label = fn ($k) => self::CASH_CATS[$k] ?? $this->nice($k);

        return $this->respond('Kassalar statistikasi', [
            $this->kpi('Jami naqd qoldiq', $this->m($balance), 'money', 'barcha kassalar bo‘yicha', 'blue', 'wallet'),
            $this->kpi('Faol kassalar', $activeBoxes, 'int', $inactiveBoxes.' ta nofaol', 'violet', 'package'),
            $this->kpi('30 kunlik kirim', $this->m($io->i), 'money', null, 'emerald', 'trend'),
            $this->kpi('30 kunlik chiqim', $this->m($io->o), 'money', null, 'rose', 'banknote'),
            $this->kpi('Sof oqim', $this->m((float) $io->i - (float) $io->o), 'money', (float) $io->i >= (float) $io->o ? 'kassa o‘sgan' : 'kassa kamaygan', (float) $io->i >= (float) $io->o ? 'cyan' : 'orange', 'activity'),
            $this->kpi('Operatsiyalar', (int) $io->c, 'int', '30 kun', 'slate', 'receipt'),
        ], [
            $this->chart('area', 'Kassa qoldig‘i dinamikasi', $curve, ['format' => 'money', 'wide' => true, 'hint' => 'Jami naqd qoldiq, kun oxiriga', 'color' => '#2563eb']),
            $this->chart('donut', 'Kassalar bo‘yicha qoldiq', $this->lv($perBox->filter(fn ($r) => (float) $r->total > 0)), ['format' => 'money']),
            $this->chart('bars', 'Kirim va chiqim', $flow, [
                'format' => 'money', 'wide' => true, 'hint' => 'Kunlik naqd pul harakati',
                'series' => [['key' => 'incoming', 'name' => 'Kirim', 'color' => '#10b981'], ['key' => 'outgoing', 'name' => 'Chiqim', 'color' => '#f43f5e']],
            ]),
            $this->chart('hbar', 'Kirim manbalari', $this->lv($cat('in'), 'category', 'total', $label), ['format' => 'money', 'color' => '#10b981']),
            $this->chart('hbar', 'Chiqim yo‘nalishlari', $this->lv($cat('out'), 'category', 'total', $label), ['format' => 'money', 'color' => '#f43f5e']),
            $this->chart('hbar', 'Kassalar aylanmasi', $this->lv($turnover), ['format' => 'money', 'hint' => '30 kun, kirim + chiqim', 'color' => '#8b5cf6']),
            $this->chart('table', 'Eng katta operatsiyalar', collect($largest)->map(fn ($r) => [
                'name' => ($r->direction === 'in' ? '＋ ' : '－ ').$label($r->category).' · '.$r->box, 'date' => substr((string) $r->occurred_at, 0, 10), 'amount' => (float) $r->amount,
            ])->all(), ['columns' => [['key' => 'name', 'label' => 'Operatsiya'], ['key' => 'date', 'label' => 'Sana'], ['key' => 'amount', 'label' => 'Summa', 'format' => 'money']]]),
        ]);
    }

    // ------------------------------------------------------------------ BANK

    public function bank(Request $request)
    {
        $from = $this->from()->toDateString().' 00:00:00';
        $when = 'COALESCE(bank_transactions.occurred_at, bank_transactions.created_at)';
        $inTypes = "('deposit','cash_to_bank')";
        $outTypes = "('withdrawal','bank_to_cash','expense')";
        $ok = fn () => DB::table('bank_transactions')->where('bank_transactions.status', 'approved');
        $range = fn () => $ok()->whereRaw("$when >= ?", [$from]);

        $accounts = DB::table('bank_accounts')->selectRaw(
            "bank_accounts.id, bank_accounts.name, bank_accounts.is_active, bank_accounts.opening_balance
             + COALESCE((SELECT SUM(t.amount) FROM bank_transactions t WHERE t.status = 'approved' AND t.to_account_id = bank_accounts.id), 0)
             - COALESCE((SELECT SUM(t.amount) FROM bank_transactions t WHERE t.status = 'approved' AND t.from_account_id = bank_accounts.id), 0) AS balance"
        )->get();
        $balance = (float) $accounts->sum(fn ($a) => (float) $a->balance);
        $activeAcc = $accounts->filter(fn ($a) => (bool) $a->is_active)->count();

        $flow = $range()->selectRaw("COALESCE(SUM(CASE WHEN type IN $inTypes THEN amount ELSE 0 END),0) i, COALESCE(SUM(CASE WHEN type IN $outTypes THEN amount ELSE 0 END),0) o, COUNT(*) c")->first();
        $draft = DB::table('bank_transactions')->where('status', 'draft')->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) t')->first();

        $daily = $range()->selectRaw("DATE($when) d, COALESCE(SUM(CASE WHEN type IN $inTypes THEN amount ELSE 0 END),0) incoming, COALESCE(SUM(CASE WHEN type IN $outTypes THEN amount ELSE 0 END),0) outgoing")
            ->groupBy(DB::raw("DATE($when)"))->get();
        $series = $this->dailySeries($daily, ['incoming', 'outgoing']);
        $running = $balance - ((float) $flow->i - (float) $flow->o); // davr boshidagi jami qoldiq (hisoblararo o‘tkazma ta‘sir qilmaydi)
        $curve = array_map(function ($r) use (&$running) {
            $running += $r['incoming'] - $r['outgoing'];

            return ['label' => $r['label'], 'value' => round($running, 2)];
        }, $series);

        $types = $range()->selectRaw('type, COALESCE(SUM(amount),0) total')->groupBy('type')->orderByDesc('total')->get();
        $largest = $range()->selectRaw("number, type, amount, $when AS occurred")->orderByDesc('amount')->limit(7)->get();
        $typeLabels = ['deposit' => 'Bankka kirim', 'withdrawal' => 'Bankdan chiqim', 'transfer' => 'Hisoblararo o‘tkazma', 'cash_to_bank' => 'Kassadan bankka', 'bank_to_cash' => 'Bankdan kassaga', 'expense' => 'Xarajat'];
        $byAccount = $accounts->sortByDesc(fn ($a) => (float) $a->balance)->take(8)->values();

        return $this->respond('Bank statistikasi', [
            $this->kpi('Jami bank qoldig‘i', $this->m($balance), 'money', 'barcha hisoblar bo‘yicha', 'blue', 'wallet'),
            $this->kpi('Faol hisoblar', $activeAcc, 'int', ($accounts->count() - $activeAcc).' ta nofaol', 'violet', 'package'),
            $this->kpi('30 kunlik kirim', $this->m($flow->i), 'money', 'kassadan va tashqaridan', 'emerald', 'trend'),
            $this->kpi('30 kunlik chiqim', $this->m($flow->o), 'money', 'xarajat va yechib olish', 'rose', 'banknote'),
            $this->kpi('Tasdiq kutmoqda', (int) $draft->c, 'int', number_format((float) $draft->t, 0, '.', ' ').' so‘m', 'amber', 'clock'),
            $this->kpi('Operatsiyalar', (int) $flow->c, 'int', 'tasdiqlangan, 30 kun', 'slate', 'receipt'),
        ], [
            $this->chart('area', 'Bank qoldig‘i dinamikasi', $curve, ['format' => 'money', 'wide' => true, 'hint' => 'Jami qoldiq, kun oxiriga', 'color' => '#2563eb']),
            $this->chart('donut', 'Operatsiya turlari', $this->lv($types, 'type', 'total', fn ($k) => $typeLabels[$k] ?? $this->nice($k)), ['format' => 'money']),
            $this->chart('bars', 'Kirim va chiqim', $series, [
                'format' => 'money', 'wide' => true, 'hint' => 'Kunlik bank harakati',
                'series' => [['key' => 'incoming', 'name' => 'Kirim', 'color' => '#10b981'], ['key' => 'outgoing', 'name' => 'Chiqim', 'color' => '#f43f5e']],
            ]),
            $this->chart('hbar', 'Hisoblar qoldig‘i', $this->lv($byAccount, 'name', 'balance'), ['format' => 'money', 'color' => '#8b5cf6']),
            $this->chart('table', 'Eng katta operatsiyalar', collect($largest)->map(fn ($r) => [
                'name' => ($typeLabels[$r->type] ?? $this->nice($r->type)).' · '.$r->number, 'date' => substr((string) $r->occurred, 0, 10), 'amount' => (float) $r->amount,
            ])->all(), ['columns' => [['key' => 'name', 'label' => 'Operatsiya'], ['key' => 'date', 'label' => 'Sana'], ['key' => 'amount', 'label' => 'Summa', 'format' => 'money']]]),
        ]);
    }

    // ------------------------------------------------------------------ OMBORLAR

    public function warehouses(Request $request)
    {
        $cost = 'COALESCE(NULLIF(products.avg_cost, 0), products.purchase_price, 0)';
        $stk = fn () => DB::table('warehouse_stocks')->join('products', 'products.id', '=', 'warehouse_stocks.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_stocks.warehouse_id')->whereNull('products.deleted_at');
        $t = $stk()->selectRaw(
            "COUNT(CASE WHEN warehouse_stocks.quantity > 0 THEN 1 END) positions,
             COALESCE(SUM(CASE WHEN warehouse_stocks.quantity > 0 THEN warehouse_stocks.quantity * $cost ELSE 0 END),0) cost,
             COALESCE(SUM(CASE WHEN warehouse_stocks.quantity > 0 THEN warehouse_stocks.quantity * products.sale_price ELSE 0 END),0) retail,
             COUNT(CASE WHEN warehouse_stocks.quantity < 0 THEN 1 END) negative"
        )->first();
        $active = DB::table('warehouses')->where('is_active', true)->count();
        $inactive = DB::table('warehouses')->where('is_active', false)->count();

        $lowQ = fn () => DB::table('products')->leftJoin('warehouse_stocks', 'warehouse_stocks.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')->where('products.is_active', true)->groupBy('products.id', 'products.name', 'products.min_stock')
            ->havingRaw('COALESCE(SUM(warehouse_stocks.quantity),0) <= products.min_stock');
        $lowCount = DB::query()->fromSub($lowQ()->selectRaw('products.id'), 'l')->count();
        $lowRows = $lowQ()->selectRaw('products.name, products.min_stock, COALESCE(SUM(warehouse_stocks.quantity),0) qty')->orderByRaw('COALESCE(SUM(warehouse_stocks.quantity),0) ASC')->limit(7)->get();

        $byWh = $stk()->where('warehouse_stocks.quantity', '>', 0)->selectRaw("warehouses.name, COALESCE(SUM(warehouse_stocks.quantity * $cost),0) total")
            ->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('total')->limit(8)->get();
        $positions = $stk()->where('warehouse_stocks.quantity', '>', 0)->selectRaw('warehouses.name, COUNT(*) total')->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('total')->limit(8)->get();
        $cats = $stk()->leftJoin('categories', 'categories.id', '=', 'products.category_id')->where('warehouse_stocks.quantity', '>', 0)
            ->selectRaw("COALESCE(categories.name, 'Kategoriyasiz') name, COALESCE(SUM(warehouse_stocks.quantity * $cost),0) total")->groupBy('categories.id', 'categories.name')->orderByDesc('total')->get();
        $catRows = $this->lv($cats->take(6));
        if ($cats->count() > 6) {
            $catRows[] = ['label' => 'Boshqa', 'value' => (float) $cats->slice(6)->sum('total')];
        }
        $topValue = $stk()->where('warehouse_stocks.quantity', '>', 0)->selectRaw("products.name, COALESCE(SUM(warehouse_stocks.quantity * $cost),0) total")
            ->groupBy('products.id', 'products.name')->orderByDesc('total')->limit(8)->get();
        $margin = (float) $t->cost > 0 ? (((float) $t->retail - (float) $t->cost) * 100 / (float) $t->cost) : 0;

        return $this->respond('Omborlar statistikasi', [
            $this->kpi('Faol omborlar', $active, 'int', $inactive.' ta nofaol', 'blue', 'boxes'),
            $this->kpi('Mahsulot pozitsiyalari', (int) $t->positions, 'int', 'qoldig‘i bor', 'violet', 'package'),
            $this->kpi('Qoldiq qiymati', $this->m($t->cost), 'money', 'tannarx bo‘yicha', 'emerald', 'wallet'),
            $this->kpi('Sotuv qiymati', $this->m($t->retail), 'money', 'kutilayotgan ustama '.round($margin).'%', 'cyan', 'trend'),
            $this->kpi('Kam qolgan', $lowCount, 'int', 'minimal qoldiqdan past', 'orange', 'alert'),
            $this->kpi('Manfiy qoldiq', (int) $t->negative, 'int', 'tekshirish kerak', 'rose', 'alert'),
        ], [
            $this->chart('hbar', 'Omborlar qiymati', $this->lv($byWh), ['format' => 'money', 'hint' => 'Tannarx bo‘yicha qoldiq', 'color' => '#2563eb']),
            $this->chart('donut', 'Kategoriyalar ulushi', $catRows, ['format' => 'money', 'hint' => 'Qoldiq qiymati bo‘yicha']),
            $this->chart('hbar', 'Pozitsiyalar soni', $this->lv($positions), ['format' => 'int', 'hint' => 'Omborlarda turli mahsulotlar', 'color' => '#06b6d4']),
            $this->chart('hbar', 'Eng qimmat qoldiqlar', $this->lv($topValue), ['format' => 'money', 'color' => '#10b981']),
            $this->chart('table', 'Eng kam qolgan mahsulotlar', collect($lowRows)->map(fn ($r) => ['name' => $r->name, 'min' => (float) $r->min_stock, 'qty' => (float) $r->qty])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Mahsulot'], ['key' => 'min', 'label' => 'Minimal', 'format' => 'int'], ['key' => 'qty', 'label' => 'Qoldiq', 'format' => 'int']],
            ]),
        ], 'Joriy holat');
    }

    // ------------------------------------------------------------------ MAHSULOT PARTIYALARI

    public function batches(Request $request)
    {
        $today = Carbon::today();
        $d = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        [$d0, $d7, $d30, $d90, $d180] = [$d(0), $d(7), $d(30), $d(90), $d(180)];

        $rows = DB::table('product_batches')->join('products', 'products.id', '=', 'product_batches.product_id')->join('warehouses', 'warehouses.id', '=', 'product_batches.warehouse_id')
            ->where('product_batches.quantity_remaining', '>', 0)
            ->get(['products.name as product', 'product_batches.batch_number', 'warehouses.name as wh', 'product_batches.expires_at', 'product_batches.quantity_remaining as qty', 'product_batches.unit_cost as cost']);

        $labels = ['Muddati o‘tgan', '0–7 kun', '8–30 kun', '31–90 kun', '91–180 kun', '180 kundan ortiq', 'Muddatsiz'];
        $count = array_fill(0, 7, 0.0);
        $value = array_fill(0, 7, 0.0);
        $risk = [];
        $total = 0.0;
        $nearest = null;
        foreach ($rows as $r) {
            $v = (float) $r->qty * (float) $r->cost;
            $e = $r->expires_at ? substr((string) $r->expires_at, 0, 10) : null;
            $i = match (true) {
                $e === null => 6, $e < $d0 => 0, $e <= $d7 => 1, $e <= $d30 => 2, $e <= $d90 => 3, $e <= $d180 => 4, default => 5,
            };
            $count[$i]++;
            $value[$i] += $v;
            $total += $v;
            if ($i <= 2) {
                $risk[$r->wh] = ($risk[$r->wh] ?? 0) + $v;
            }
            if ($e !== null && $e >= $d0 && ($nearest === null || $e < $nearest)) {
                $nearest = $e;
            }
        }
        arsort($risk);
        $riskRows = [];
        foreach (array_slice($risk, 0, 8, true) as $name => $v) {
            $riskRows[] = ['label' => (string) $name, 'value' => round($v, 2)];
        }
        $soon = $rows->filter(fn ($r) => $r->expires_at)->sortBy('expires_at')->take(8);

        return $this->respond('Partiyalar statistikasi', [
            $this->kpi('Faol partiyalar', $rows->count(), 'int', 'qoldig‘i bor', 'blue', 'boxes'),
            $this->kpi('Muddati o‘tgan', (int) $count[0], 'int', number_format($value[0], 0, '.', ' ').' so‘m', 'rose', 'alert'),
            $this->kpi('30 kunda tugaydi', (int) ($count[1] + $count[2]), 'int', number_format($value[1] + $value[2], 0, '.', ' ').' so‘m', 'orange', 'clock'),
            $this->kpi('Xavf ostidagi qiymat', $this->m($value[0] + $value[1] + $value[2]), 'money', 'o‘tgan + 30 kun ichida', 'amber', 'wallet'),
            $this->kpi('Jami partiya qiymati', $this->m($total), 'money', 'tannarx bo‘yicha', 'emerald', 'trend'),
            $this->kpi('Eng yaqin muddat', $nearest ? Carbon::parse($nearest)->format('d.m.Y') : '—', 'text', $nearest ? (int) Carbon::parse($nearest)->diffInDays($today, true).' kundan keyin' : null, 'cyan', 'clock'),
        ], [
            $this->chart('bars', 'Muddat bo‘yicha partiyalar', array_map(fn ($l, $c) => ['label' => $l, 'value' => $c], $labels, $count), ['format' => 'int', 'wide' => true, 'hint' => 'Yaroqlilik muddatigacha qolgan vaqt', 'color' => '#f59e0b']),
            $this->chart('donut', 'Qiymat taqsimoti', array_map(fn ($l, $v) => ['label' => $l, 'value' => round($v, 2)], $labels, $value), ['format' => 'money']),
            $this->chart('hbar', 'Omborlar bo‘yicha xavf', $riskRows, ['format' => 'money', 'hint' => 'Muddati o‘tgan va 30 kunda tugaydigan', 'color' => '#f43f5e']),
            $this->chart('table', 'Eng yaqin muddatlar', $soon->map(fn ($r) => ['name' => $r->product.' · '.$r->batch_number, 'date' => substr((string) $r->expires_at, 0, 10), 'qty' => (float) $r->qty])->values()->all(), [
                'columns' => [['key' => 'name', 'label' => 'Partiya'], ['key' => 'date', 'label' => 'Muddat'], ['key' => 'qty', 'label' => 'Qoldiq', 'format' => 'int']],
            ]),
        ], 'Joriy holat');
    }

    // ------------------------------------------------------------------ NARXLASH (aksiya va chegirmalar)

    public function pricing(Request $request)
    {
        $from = $this->from()->toDateString();
        $now = Carbon::now();
        $active = fn () => DB::table('price_rules')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
        $activeCount = $active()->count();
        $all = DB::table('price_rules')->count();
        $soon = $active()->whereNotNull('ends_at')->where('ends_at', '<=', $now->copy()->addDays(7))->count();
        $stale = DB::table('price_rules')->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<', $now)->count();
        $sales = fn () => DB::table('sales')->where('status', 'approved')->where('type', 'sale')->whereDate('sold_at', '>=', $from);
        $disc = $sales()->selectRaw('COALESCE(SUM(discount),0) d, COALESCE(SUM(subtotal),0) s')->first();
        $share = (float) $disc->s > 0 ? ((float) $disc->d * 100 / (float) $disc->s) : 0;

        $daily = $sales()->selectRaw('DATE(sold_at) d, COALESCE(SUM(discount),0) value')->groupBy(DB::raw('DATE(sold_at)'))->get();
        $kinds = $active()->selectRaw('kind, COUNT(*) total')->groupBy('kind')->orderByDesc('total')->get();
        $dtype = $active()->selectRaw('discount_type, COUNT(*) total')->groupBy('discount_type')->orderByDesc('total')->get();
        $kindLabels = ['promotion' => 'Aksiya', 'customer_discount' => 'Mijoz chegirmasi', 'loyalty_redemption' => 'Ballni ishlatish'];
        $biggest = $active()->where('discount_type', 'percent')->orderByDesc('discount_value')->limit(8)->get(['name', 'discount_value']);
        $products = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw('products.name, COALESCE(SUM(sale_items.discount + sale_items.discount_share),0) total')->groupBy('products.id', 'products.name')
            ->havingRaw('COALESCE(SUM(sale_items.discount + sale_items.discount_share),0) > 0')->orderByDesc('total')->limit(8)->get();
        $ending = $active()->whereNotNull('ends_at')->orderBy('ends_at')->limit(7)->get(['name', 'ends_at', 'discount_type', 'discount_value']);

        return $this->respond('Aksiya va chegirmalar statistikasi', [
            $this->kpi('Faol qoidalar', $activeCount, 'int', 'jami '.$all.' ta qoidadan', 'blue', 'tag'),
            $this->kpi('7 kunda tugaydi', $soon, 'int', 'faol aksiyalar', 'orange', 'clock'),
            $this->kpi('Muddati o‘tgan', $stale, 'int', 'hali faol deb belgilangan', 'rose', 'alert'),
            $this->kpi('Berilgan chegirma', $this->m($disc->d), 'money', '30 kunlik sotuvlarda', 'violet', 'wallet'),
            $this->kpi('Chegirma ulushi', round($share, 1), 'pct', 'chegirmadan oldingi savdoga nisbatan', 'amber', 'receipt'),
            $this->kpi('Faol aksiyalar', (int) ($kinds->firstWhere('kind', 'promotion')->total ?? 0), 'int', 'mijoz chegirmalari: '.(int) ($kinds->firstWhere('kind', 'customer_discount')->total ?? 0), 'emerald', 'trend'),
        ], [
            $this->chart('area', 'Kunlik berilgan chegirma', $this->dailySeries($daily, ['value']), ['format' => 'money', 'wide' => true, 'hint' => 'Tasdiqlangan sotuvlardagi chegirmalar', 'color' => '#8b5cf6']),
            $this->chart('donut', 'Qoidalar turi', $this->lv($kinds, 'kind', 'total', fn ($k) => $kindLabels[$k] ?? $this->nice($k)), ['format' => 'int', 'hint' => 'Faol qoidalar']),
            $this->chart('hbar', 'Eng ko‘p chegirma berilgan mahsulotlar', $this->lv($products), ['format' => 'money', 'hint' => '30 kun', 'color' => '#f59e0b']),
            $this->chart('donut', 'Chegirma usuli', $this->lv($dtype, 'discount_type', 'total', fn ($k) => $k === 'percent' ? 'Foiz' : ($k === 'fixed' ? 'Qat‘iy summa' : $this->nice($k))), ['format' => 'int']),
            $this->chart('hbar', 'Eng yuqori foizli chegirmalar', $this->lv($biggest, 'name', 'discount_value'), ['format' => 'pct', 'color' => '#f43f5e']),
            $this->chart('table', 'Tez tugaydigan qoidalar', collect($ending)->map(fn ($r) => [
                'name' => $r->name, 'date' => substr((string) $r->ends_at, 0, 10),
                'value' => $r->discount_type === 'percent' ? rtrim(rtrim(number_format((float) $r->discount_value, 2, '.', ''), '0'), '.').'%' : number_format((float) $r->discount_value, 0, '.', ' ').' so‘m',
            ])->all(), ['columns' => [['key' => 'name', 'label' => 'Qoida'], ['key' => 'date', 'label' => 'Tugaydi'], ['key' => 'value', 'label' => 'Chegirma']]]),
        ]);
    }

    // ------------------------------------------------------------------ SODIQLIK KARTALARI

    public function loyalty(Request $request)
    {
        $from = $this->from()->toDateString();
        $cards = DB::table('loyalty_accounts')->where('is_active', true)->count();
        $customers = DB::table('customers')->where('is_active', true)->count();
        $sum = DB::table('loyalty_accounts')->selectRaw('COALESCE(SUM(points_balance),0) b, COALESCE(SUM(lifetime_points),0) l')->first();
        $pts = DB::table('loyalty_transactions')->whereDate('created_at', '>=', $from)
            ->selectRaw('COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END),0) earned, COALESCE(SUM(CASE WHEN points_delta < 0 THEN -points_delta ELSE 0 END),0) spent')->first();
        $coverage = $customers > 0 ? min(100, $cards * 100 / $customers) : 0;
        $usage = (float) $pts->earned > 0 ? ((float) $pts->spent * 100 / (float) $pts->earned) : 0;

        $daily = DB::table('loyalty_transactions')->whereDate('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) d, COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END),0) earned, COALESCE(SUM(CASE WHEN points_delta < 0 THEN -points_delta ELSE 0 END),0) spent')
            ->groupBy(DB::raw('DATE(created_at)'))->get();
        $newCards = DB::table('loyalty_accounts')->whereDate('created_at', '>=', $from)->selectRaw('DATE(created_at) d, COUNT(*) value')->groupBy(DB::raw('DATE(created_at)'))->get();
        $topBal = DB::table('loyalty_accounts')->join('customers', 'customers.id', '=', 'loyalty_accounts.customer_id')->where('loyalty_accounts.points_balance', '>', 0)
            ->orderByDesc('loyalty_accounts.points_balance')->limit(8)->get(['customers.name', 'loyalty_accounts.points_balance as total']);
        $topLife = DB::table('loyalty_accounts')->join('customers', 'customers.id', '=', 'loyalty_accounts.customer_id')
            ->orderByDesc('loyalty_accounts.lifetime_points')->limit(7)->get(['customers.name', 'loyalty_accounts.points_balance', 'loyalty_accounts.lifetime_points']);

        return $this->respond('Sodiqlik dasturi statistikasi', [
            $this->kpi('Faol kartalar', $cards, 'int', 'jami '.DB::table('loyalty_accounts')->count().' ta', 'blue', 'users'),
            $this->kpi('Mijozlar qamrovi', round($coverage, 1), 'pct', 'kartali faol mijozlar ulushi', 'emerald', 'trend'),
            $this->kpi('Joriy ball balansi', $sum->b, 'int', 'barcha kartalarda', 'violet', 'wallet'),
            $this->kpi('Berilgan ball', $pts->earned, 'int', '30 kun', 'cyan', 'trend'),
            $this->kpi('Ishlatilgan ball', $pts->spent, 'int', 'berilganning '.round($usage).'%', 'amber', 'receipt'),
            $this->kpi('Umr bo‘yi yig‘ilgan', $sum->l, 'int', 'ball', 'slate', 'activity'),
        ], [
            $this->chart('bars', 'Ball harakati', $this->dailySeries($daily, ['earned', 'spent']), [
                'format' => 'int', 'wide' => true, 'hint' => 'Kunlik berilgan va ishlatilgan ballar',
                'series' => [['key' => 'earned', 'name' => 'Berilgan', 'color' => '#10b981'], ['key' => 'spent', 'name' => 'Ishlatilgan', 'color' => '#f59e0b']],
            ]),
            $this->chart('donut', 'Karta qamrovi', [['label' => 'Kartali', 'value' => (float) min($cards, $customers)], ['label' => 'Kartasiz', 'value' => (float) max(0, $customers - $cards)]], ['format' => 'int', 'hint' => 'Faol mijozlar']),
            $this->chart('bars', 'Yangi kartalar', $this->dailySeries($newCards, ['value']), ['format' => 'int', 'wide' => true, 'hint' => 'Kunlik berilgan kartalar', 'color' => '#6366f1']),
            $this->chart('hbar', 'Eng katta ball balansi', $this->lv($topBal), ['format' => 'int', 'color' => '#8b5cf6']),
            $this->chart('table', 'Eng faol kartalar', collect($topLife)->map(fn ($r) => ['name' => $r->name, 'bal' => (int) $r->points_balance, 'life' => (int) $r->lifetime_points])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Mijoz'], ['key' => 'bal', 'label' => 'Balans', 'format' => 'int'], ['key' => 'life', 'label' => 'Jami yig‘ilgan', 'format' => 'int']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ FOYDALANUVCHILAR

    public function users(Request $request)
    {
        $from = $this->from()->toDateString();
        $u = fn () => DB::table('users');
        $total = $u()->count();
        $active = $u()->where('status', 'active')->count();
        $logged = $u()->whereDate('last_login_at', '>=', $from)->count();
        $never = $u()->where('status', 'active')->whereNull('last_login_at')->count();
        $twofa = $u()->where('status', 'active')->whereNotNull('two_factor_confirmed_at')->count();
        $fresh = $u()->whereDate('created_at', '>=', $from)->count();
        $noRole = $u()->where('status', 'active')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('role_user')->whereColumn('role_user.user_id', 'users.id'))->count();

        $roles = DB::table('roles')->leftJoin('role_user', 'role_user.role_id', '=', 'roles.id')->selectRaw('roles.name, COUNT(role_user.user_id) total')
            ->groupBy('roles.id', 'roles.name')->orderByDesc('total')->get();
        $logins = $u()->whereDate('last_login_at', '>=', $from)->selectRaw('DATE(last_login_at) d, COUNT(*) value')->groupBy(DB::raw('DATE(last_login_at)'))->get();
        $statuses = $u()->selectRaw('status, COUNT(*) total')->groupBy('status')->orderByDesc('total')->get();
        $sellers = DB::table('sales')->join('users', 'users.id', '=', 'sales.created_by')->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw('users.name, COUNT(*) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        $recent = $u()->whereNotNull('last_login_at')->orderByDesc('last_login_at')->limit(7)->get(['name', 'last_login_at', 'last_login_ip']);
        $statusLabels = ['active' => 'Faol', 'inactive' => 'Nofaol', 'blocked' => 'Bloklangan', 'suspended' => 'To‘xtatilgan'];

        return $this->respond('Foydalanuvchilar statistikasi', [
            $this->kpi('Faol foydalanuvchilar', $active, 'int', 'jami '.$total.' ta', 'blue', 'users'),
            $this->kpi('30 kunda kirganlar', $logged, 'int', 'tizimga kirgan hisoblar', 'emerald', 'activity'),
            $this->kpi('Hech kirmagan', $never, 'int', 'faol, lekin kirmagan', 'amber', 'clock'),
            $this->kpi('2FA himoyasi', $active ? round($twofa * 100 / $active, 1) : 0, 'pct', $twofa.' ta faol hisobda yoqilgan', 'violet', 'alert'),
            $this->kpi('Yangi hisoblar', $fresh, 'int', 'so‘nggi 30 kun', 'cyan', 'trend'),
            $this->kpi('Rolsiz hisoblar', $noRole, 'int', 'faol, rol biriktirilmagan', 'rose', 'alert'),
        ], [
            $this->chart('bars', 'Kirishlar', $this->dailySeries($logins, ['value']), ['format' => 'int', 'wide' => true, 'hint' => 'Oxirgi kirish sanasi bo‘yicha foydalanuvchilar', 'color' => '#2563eb']),
            $this->chart('donut', 'Rollar bo‘yicha', $this->lv($roles), ['format' => 'int', 'hint' => 'Rol biriktirilgan foydalanuvchilar']),
            $this->chart('donut', '2FA holati', [['label' => 'Yoqilgan', 'value' => (float) $twofa], ['label' => 'Yoqilmagan', 'value' => (float) max(0, $active - $twofa)]], ['format' => 'int', 'hint' => 'Faol hisoblar']),
            $this->chart('hbar', 'Eng faol sotuvchilar', $this->lv($sellers), ['format' => 'int', 'hint' => '30 kun, cheklar soni', 'color' => '#10b981']),
            $this->chart('donut', 'Hisob holati', $this->lv($statuses, 'status', 'total', fn ($k) => $statusLabels[$k] ?? $this->nice($k)), ['format' => 'int']),
            $this->chart('table', 'Oxirgi kirishlar', collect($recent)->map(fn ($r) => ['name' => $r->name, 'at' => substr((string) $r->last_login_at, 0, 16), 'ip' => $r->last_login_ip ?: '—'])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Foydalanuvchi'], ['key' => 'at', 'label' => 'Vaqt'], ['key' => 'ip', 'label' => 'IP']],
            ]),
        ], 'Joriy holat');
    }

    // ------------------------------------------------------------------ XODIMLAR

    public function employees(Request $request)
    {
        $from = $this->from()->toDateString();
        $today = Carbon::today();
        $e = fn () => DB::table('employees');
        $total = $e()->count();
        $active = $e()->where('status', 'active')->count();
        $fund = (float) $e()->where('status', 'active')->sum('salary');
        $paid = $e()->where('status', 'active')->where('salary', '>', 0)->count();
        $fresh = $e()->whereDate('hire_date', '>=', $from)->count();
        $hired = $e()->where('status', 'active')->whereNotNull('hire_date')->pluck('hire_date');
        $avgYears = $hired->count() ? $hired->avg(fn ($d) => Carbon::parse($d)->diffInDays($today, true) / 365.25) : null;
        $todayPlan = DB::table('work_schedules')->whereDate('work_date', $today->toDateString())->selectRaw('type, COUNT(*) c')->groupBy('type')->get()->pluck('c', 'type');
        $working = (int) ($todayPlan['work'] ?? 0);
        $resting = (int) $todayPlan->except('work')->sum();

        $positions = $e()->where('status', 'active')->selectRaw('employees.position pos, COUNT(*) total')->groupBy('employees.position')->orderByDesc('total')->get();
        $statuses = $e()->selectRaw('status, COUNT(*) total')->groupBy('status')->orderByDesc('total')->get();
        $payroll = $e()->where('status', 'active')->where('salary', '>', 0)->selectRaw('employees.position pos, COALESCE(SUM(salary),0) total')->groupBy('employees.position')->orderByDesc('total')->limit(8)->get();
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $s = Carbon::today()->startOfMonth()->subMonths($i);
            $months[] = ['label' => $s->format('m.Y'), 'value' => (float) $e()->whereDate('hire_date', '>=', $s->toDateString())->whereDate('hire_date', '<=', $s->copy()->endOfMonth()->toDateString())->count()];
        }
        $sellers = DB::table('sales')->join('employees', 'employees.user_id', '=', 'sales.created_by')->join('users', 'users.id', '=', 'sales.created_by')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw('users.name, COALESCE(SUM(sales.total),0) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        $plan = DB::table('work_schedules')->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $today->toDateString())->selectRaw('type, COUNT(*) total')->groupBy('type')->orderByDesc('total')->get();
        $newest = $e()->join('users', 'users.id', '=', 'employees.user_id')->orderByDesc('employees.hire_date')->limit(7)->get(['users.name', 'employees.position', 'employees.hire_date']);
        $planLabels = ['work' => 'Ishlaydi', 'off' => 'Dam oladi', 'vacation' => 'Ta‘til', 'sick' => 'Kasallik', 'absent_excused' => 'Sababli kelmagan', 'absent_unexcused' => 'Sababsiz kelmagan'];
        $statusLabels = ['active' => 'Faol', 'inactive' => 'Nofaol', 'terminated' => 'Bo‘shagan', 'on_leave' => 'Ta‘tilda'];

        return $this->respond('Xodimlar statistikasi', [
            $this->kpi('Faol xodimlar', $active, 'int', 'jami '.$total.' ta', 'blue', 'users'),
            $this->kpi('Oylik fond', $this->m($fund), 'money', 'faol xodimlar maoshi', 'violet', 'wallet'),
            $this->kpi('O‘rtacha oylik', $this->m($paid ? $fund / $paid : 0), 'money', 'maosh belgilanganlar bo‘yicha', 'cyan', 'banknote'),
            $this->kpi('Yangi xodimlar', $fresh, 'int', 'so‘nggi 30 kun', 'emerald', 'trend'),
            $this->kpi('O‘rtacha staj', $avgYears === null ? '—' : round($avgYears, 1).' yil', 'text', 'faol xodimlar bo‘yicha', 'amber', 'clock'),
            $this->kpi('Bugun ishda', $working, 'int', 'dam/ta‘til/kasal: '.$resting, 'orange', 'activity'),
        ], [
            $this->chart('bars', 'Ishga olish dinamikasi', $months, ['format' => 'int', 'wide' => true, 'hint' => 'So‘nggi 12 oy', 'color' => '#10b981']),
            $this->chart('donut', 'Lavozimlar bo‘yicha', $this->lv($positions, 'pos', 'total'), ['format' => 'int', 'hint' => 'Faol xodimlar']),
            $this->chart('hbar', 'Lavozimlar bo‘yicha oylik fond', $this->lv($payroll, 'pos', 'total'), ['format' => 'money', 'color' => '#8b5cf6']),
            $this->chart('hbar', 'Xodimlar savdosi', $this->lv($sellers), ['format' => 'money', 'hint' => '30 kun, savdo summasi', 'color' => '#2563eb']),
            $this->chart('donut', 'Ish jadvali', $this->lv($plan, 'type', 'total', fn ($k) => $planLabels[$k] ?? $this->nice($k)), ['format' => 'int', 'hint' => 'So‘nggi 30 kun, kunlar soni']),
            $this->chart('donut', 'Xodim holati', $this->lv($statuses, 'status', 'total', fn ($k) => $statusLabels[$k] ?? $this->nice($k)), ['format' => 'int']),
            $this->chart('table', 'Eng yangi xodimlar', collect($newest)->map(fn ($r) => ['name' => $r->name, 'pos' => $r->position, 'date' => substr((string) $r->hire_date, 0, 10) ?: '—'])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Xodim'], ['key' => 'pos', 'label' => 'Lavozim'], ['key' => 'date', 'label' => 'Ishga olingan']],
            ]),
        ], 'Joriy holat');
    }

    // ------------------------------------------------------------------ KATEGORIYALAR

    public function categories(Request $request)
    {
        $from = $this->from()->toDateString();
        $cost = 'COALESCE(NULLIF(products.avg_cost, 0), products.purchase_price, 0)';
        $name = "COALESCE(categories.name, 'Kategoriyasiz')";
        $live = fn () => DB::table('products')->whereNull('products.deleted_at')->where('products.is_active', true);
        $act = DB::table('categories')->where('is_active', true)->count();
        $arch = DB::table('categories')->where('is_active', false)->count();
        $uncat = $live()->whereNull('products.category_id')->count();
        $empty = DB::table('categories')->where('categories.is_active', true)->whereNotExists(
            fn ($q) => $q->select(DB::raw(1))->from('products')->whereColumn('products.category_id', 'categories.id')->whereNull('products.deleted_at')->where('products.is_active', true)
        )->count();

        $cnt = $live()->leftJoin('categories', 'categories.id', '=', 'products.category_id')->selectRaw("$name name, COUNT(*) total")
            ->groupBy('categories.id', 'categories.name')->orderByDesc('total')->get();
        $catRows = $this->lv($cnt->take(6));
        if ($cnt->count() > 6) {
            $catRows[] = ['label' => 'Boshqa', 'value' => (float) $cnt->slice(6)->sum('total')];
        }
        $stock = DB::table('warehouse_stocks')->join('products', 'products.id', '=', 'warehouse_stocks.product_id')->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('products.deleted_at')->where('warehouse_stocks.quantity', '>', 0)
            ->selectRaw("$name name, COALESCE(SUM(warehouse_stocks.quantity * $cost),0) total")->groupBy('categories.id', 'categories.name')->orderByDesc('total')->limit(8)->get();
        $sold = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw("$name name, COALESCE(SUM(sale_items.net_total),0) total")->groupBy('categories.id', 'categories.name')->orderByDesc('total')->get();
        $margin = $live()->leftJoin('categories', 'categories.id', '=', 'products.category_id')->where('products.purchase_price', '>', 0)
            ->selectRaw("$name name, AVG((products.sale_price - products.purchase_price) * 100.0 / products.purchase_price) total")->groupBy('categories.id', 'categories.name')->orderByDesc('total')->limit(8)->get();
        $soldBy = $sold->pluck('total', 'name');
        $top = $cnt->first();

        return $this->respond('Kategoriyalar statistikasi', [
            $this->kpi('Faol kategoriyalar', $act, 'int', $arch.' ta arxivda', 'blue', 'tag'),
            $this->kpi('Eng katta kategoriya', $top->name ?? '—', 'text', $top ? ((int) $top->total).' ta mahsulot' : null, 'violet', 'package'),
            $this->kpi('Kategoriyasiz mahsulot', $uncat, 'int', 'faol mahsulotlar ichida', 'amber', 'alert'),
            $this->kpi('Bo‘sh kategoriyalar', $empty, 'int', 'faol mahsulot yo‘q', 'orange', 'boxes'),
            $this->kpi('Kategoriya boshiga', $act ? round(((float) $cnt->sum('total')) / max(1, $cnt->count()), 1) : 0, 'pct', 'mahsulot (o‘rtacha, % belgisiz)', 'cyan', 'activity'),
        ], [
            $this->chart('donut', 'Mahsulotlar taqsimoti', $catRows, ['format' => 'int', 'hint' => 'Faol mahsulotlar soni']),
            $this->chart('hbar', 'Savdo bo‘yicha', $this->lv($sold->take(8)), ['format' => 'money', 'hint' => '30 kun', 'color' => '#10b981']),
            $this->chart('hbar', 'Qoldiq qiymati', $this->lv($stock), ['format' => 'money', 'hint' => 'Tannarx bo‘yicha', 'color' => '#2563eb']),
            $this->chart('hbar', 'O‘rtacha ustama', $this->lv($margin), ['format' => 'pct', 'hint' => 'Xarid narxiga nisbatan, %', 'color' => '#f59e0b']),
            $this->chart('table', 'Kategoriyalar reytingi', $cnt->take(8)->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->total, 'sales' => (float) ($soldBy[$r->name] ?? 0)])->values()->all(), [
                'columns' => [['key' => 'name', 'label' => 'Kategoriya'], ['key' => 'count', 'label' => 'Mahsulot', 'format' => 'int'], ['key' => 'sales', 'label' => 'Savdo (30 kun)', 'format' => 'money']],
            ]),
        ], 'Joriy holat va 30 kun');
    }

    // ------------------------------------------------------------------ O‘LCHOV BIRLIKLARI

    public function units(Request $request)
    {
        $from = $this->from()->toDateString();
        $act = DB::table('units')->where('is_active', true)->count();
        $inactive = DB::table('units')->where('is_active', false)->count();
        $frac = DB::table('units')->where('is_active', true)->where('allow_fraction', true)->count();
        $by = DB::table('units')->leftJoin('products', fn ($j) => $j->on('products.unit_id', '=', 'units.id')->whereNull('products.deleted_at'))
            ->selectRaw('units.name, units.short_name, units.allow_fraction, units.is_active, COUNT(products.id) total')
            ->groupBy('units.id', 'units.name', 'units.short_name', 'units.allow_fraction', 'units.is_active')->orderByDesc('total')->get();
        $unused = $by->filter(fn ($r) => (bool) $r->is_active && (int) $r->total === 0)->count();
        $soldQ = fn (string $expr) => DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')->join('units', 'units.id', '=', 'products.unit_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from)
            ->selectRaw("units.name, COALESCE(SUM($expr),0) total")->groupBy('units.id', 'units.name')->orderByDesc('total')->limit(8)->get();
        $top = $by->first();

        return $this->respond('O‘lchov birliklari statistikasi', [
            $this->kpi('Faol birliklar', $act, 'int', $inactive.' ta nofaol', 'blue', 'tag'),
            $this->kpi('Kasrli birliklar', $frac, 'int', 'kg, litr kabi o‘nlik miqdor', 'violet', 'activity'),
            $this->kpi('Ishlatilmayotgan', $unused, 'int', 'faol, mahsulot yo‘q', 'amber', 'alert'),
            $this->kpi('Eng ko‘p ishlatiladigan', $top->name ?? '—', 'text', $top ? ((int) $top->total).' ta mahsulot' : null, 'cyan', 'package'),
        ], [
            $this->chart('donut', 'Mahsulotlar birlik bo‘yicha', $this->lv($by->filter(fn ($r) => (int) $r->total > 0)->take(7)->values()), ['format' => 'int']),
            $this->chart('hbar', 'Sotilgan miqdor', $this->lv($soldQ('sale_items.quantity')), ['format' => 'int', 'hint' => '30 kun, birlik bo‘yicha', 'color' => '#10b981']),
            $this->chart('hbar', 'Savdo summasi', $this->lv($soldQ('sale_items.net_total')), ['format' => 'money', 'hint' => '30 kun', 'color' => '#8b5cf6']),
            $this->chart('donut', 'Kasr ruxsati', [['label' => 'Butun sonli', 'value' => (float) max(0, $act - $frac)], ['label' => 'Kasrli', 'value' => (float) $frac]], ['format' => 'int', 'hint' => 'Faol birliklar']),
            $this->chart('table', 'Birliklar ro‘yxati', $by->take(8)->map(fn ($r) => ['name' => $r->name.' ('.$r->short_name.')', 'count' => (int) $r->total, 'frac' => $r->allow_fraction ? 'Ha' : 'Yo‘q'])->values()->all(), [
                'columns' => [['key' => 'name', 'label' => 'Birlik'], ['key' => 'count', 'label' => 'Mahsulot', 'format' => 'int'], ['key' => 'frac', 'label' => 'Kasr']],
            ]),
        ], 'Joriy holat va 30 kun');
    }

    // ------------------------------------------------------------------ FILIALLAR

    public function branches(Request $request)
    {
        $from = $this->from()->toDateString();
        $act = DB::table('branches')->where('is_active', true)->count();
        $inactive = DB::table('branches')->where('is_active', false)->count();
        $sales = fn () => DB::table('sales')->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $from);
        $tot = $sales()->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t')->first();
        $noBranch = $sales()->whereNull('sales.branch_id')->count();
        $by = $sales()->join('branches', 'branches.id', '=', 'sales.branch_id')->selectRaw('branches.name, COUNT(*) count, COALESCE(SUM(sales.total),0) total')
            ->groupBy('branches.id', 'branches.name')->orderByDesc('total')->get();
        $infra = DB::table('branches')->where('branches.is_active', true)->orderBy('branches.name')->limit(12)->selectRaw(
            'branches.name, (SELECT COUNT(*) FROM warehouses w WHERE w.branch_id = branches.id) warehouses, (SELECT COUNT(*) FROM cashboxes c WHERE c.branch_id = branches.id) cashboxes'
        )->get();
        $cash = DB::table('cash_transactions')->join('cashboxes', 'cashboxes.id', '=', 'cash_transactions.cashbox_id')->join('branches', 'branches.id', '=', 'cashboxes.branch_id')
            ->selectRaw("branches.name, COALESCE(SUM(CASE WHEN cash_transactions.direction = 'in' THEN cash_transactions.amount ELSE -cash_transactions.amount END),0) total")
            ->groupBy('branches.id', 'branches.name')->orderByDesc('total')->limit(8)->get();
        $avg = $by->map(fn ($r) => ['label' => $r->name, 'value' => (int) $r->count ? round((float) $r->total / (int) $r->count, 2) : 0])->sortByDesc('value')->values()->all();
        $top = $by->first();

        return $this->respond('Filiallar statistikasi', [
            $this->kpi('Faol filiallar', $act, 'int', $inactive.' ta nofaol', 'blue', 'package'),
            $this->kpi('Omborlar', DB::table('warehouses')->count(), 'int', 'barcha filiallarda', 'violet', 'boxes'),
            $this->kpi('Kassalar', DB::table('cashboxes')->count(), 'int', 'barcha filiallarda', 'cyan', 'wallet'),
            $this->kpi('30 kunlik savdo', $this->m($tot->t), 'money', ((int) $tot->c).' ta chek', 'emerald', 'trend'),
            $this->kpi('Eng kuchli filial', $top->name ?? '—', 'text', $top ? number_format((float) $top->total, 0, '.', ' ').' so‘m' : null, 'amber', 'trend'),
            $this->kpi('Filialsiz savdolar', $noBranch, 'int', 'filial ko‘rsatilmagan cheklar', 'orange', 'alert'),
        ], [
            $this->chart('donut', 'Savdo ulushi', $this->lv($by), ['format' => 'money', 'hint' => '30 kun, filiallar bo‘yicha']),
            $this->chart('hbar', 'O‘rtacha chek', $avg, ['format' => 'money', 'color' => '#8b5cf6']),
            $this->chart('bars', 'Filial infratuzilmasi', $infra->map(fn ($r) => ['label' => $r->name, 'warehouses' => (float) $r->warehouses, 'cashboxes' => (float) $r->cashboxes])->all(), [
                'format' => 'int', 'wide' => true, 'hint' => 'Har bir filialdagi omborlar va kassalar',
                'series' => [['key' => 'warehouses', 'name' => 'Omborlar', 'color' => '#2563eb'], ['key' => 'cashboxes', 'name' => 'Kassalar', 'color' => '#10b981']],
            ]),
            $this->chart('hbar', 'Kassalardagi naqd qoldiq', $this->lv($cash), ['format' => 'money', 'color' => '#06b6d4']),
            $this->chart('table', 'Filiallar reytingi', $by->take(8)->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count, 'total' => (float) $r->total])->values()->all(), [
                'columns' => [['key' => 'name', 'label' => 'Filial'], ['key' => 'count', 'label' => 'Cheklar', 'format' => 'int'], ['key' => 'total', 'label' => 'Savdo', 'format' => 'money']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ YORLIQ (ETIKET) CHOP ETISH

    public function labels(Request $request)
    {
        $from = $this->from()->toDateString();
        $week = Carbon::today()->subDays(6)->toDateString();
        $live = fn () => DB::table('products')->whereNull('products.deleted_at')->where('products.is_active', true);
        $noCode = fn ($q) => $q->where(fn ($w) => $w->whereNull('products.barcode')->orWhere('products.barcode', ''));
        $total = $live()->count();
        $without = $noCode($live())->count();
        $dups = DB::query()->fromSub(
            $live()->whereNotNull('products.barcode')->where('products.barcode', '!=', '')->selectRaw('products.barcode')->groupBy('products.barcode')->havingRaw('COUNT(*) > 1'), 'd'
        )->count();
        $new7 = $live()->whereDate('products.created_at', '>=', $week)->count();
        $upd7 = $live()->whereDate('products.updated_at', '>=', $week)->whereDate('products.created_at', '<', $week)->count();
        $noPrice = $live()->where('products.sale_price', '<=', 0)->count();

        $daily = DB::table('products')->whereNull('deleted_at')->whereDate('created_at', '>=', $from)->selectRaw('DATE(created_at) d, COUNT(*) value')->groupBy(DB::raw('DATE(created_at)'))->get();
        $byCat = $noCode($live()->leftJoin('categories', 'categories.id', '=', 'products.category_id'))
            ->selectRaw("COALESCE(categories.name, 'Kategoriyasiz') name, COUNT(*) total")->groupBy('categories.id', 'categories.name')->orderByDesc('total')->limit(8)->get();
        $recent = $live()->orderByDesc('products.updated_at')->limit(7)->get(['products.name', 'products.sku', 'products.updated_at']);

        return $this->respond('Yorliq va shtrix-kod statistikasi', [
            $this->kpi('Shtrix-kod qamrovi', $total ? round(($total - $without) * 100 / $total, 1) : 0, 'pct', ($total - $without).' / '.$total.' mahsulot', 'emerald', 'tag'),
            $this->kpi('Shtrix-kodsiz', $without, 'int', 'yorliq chop etib bo‘lmaydi', 'amber', 'alert'),
            $this->kpi('Takroriy shtrix-kod', $dups, 'int', 'bir kod bir nechta mahsulotda', 'rose', 'alert'),
            $this->kpi('Yangi mahsulotlar', $new7, 'int', 'so‘nggi 7 kun — yorliq kerak', 'blue', 'package'),
            $this->kpi('Yangilangan mahsulotlar', $upd7, 'int', 'so‘nggi 7 kun — narx o‘zgargan bo‘lishi mumkin', 'violet', 'activity'),
            $this->kpi('Narxsiz mahsulotlar', $noPrice, 'int', 'sotuv narxi 0', 'orange', 'banknote'),
        ], [
            $this->chart('donut', 'Shtrix-kod holati', [['label' => 'Shtrix-kodli', 'value' => (float) ($total - $without)], ['label' => 'Shtrix-kodsiz', 'value' => (float) $without]], ['format' => 'int', 'hint' => 'Faol mahsulotlar']),
            $this->chart('bars', 'Yangi mahsulotlar', $this->dailySeries($daily, ['value']), ['format' => 'int', 'wide' => true, 'hint' => 'Kunlik qo‘shilganlar', 'color' => '#6366f1']),
            $this->chart('hbar', 'Shtrix-kodsiz — kategoriyalar', $this->lv($byCat), ['format' => 'int', 'color' => '#f59e0b']),
            $this->chart('table', 'Yorlig‘i yangilanishi mumkin', collect($recent)->map(fn ($r) => ['name' => $r->name, 'sku' => $r->sku, 'date' => substr((string) $r->updated_at, 0, 10)])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Mahsulot'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'date', 'label' => 'O‘zgargan']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ ISH JADVALI

    public function workSchedules(Request $request)
    {
        $from = $this->from()->toDateString();
        $to = Carbon::today()->toDateString();
        $ws = fn () => DB::table('work_schedules');
        $range = fn () => $ws()->whereDate('work_schedules.work_date', '>=', $from)->whereDate('work_schedules.work_date', '<=', $to);
        $todayRows = $ws()->whereDate('work_date', $to)->selectRaw('type, COUNT(*) c')->groupBy('type')->get()->pluck('c', 'type');
        $cnt = $range()->selectRaw('type, COUNT(*) c')->groupBy('type')->get()->pluck('c', 'type');
        $g = fn (string $k) => (int) ($cnt[$k] ?? 0);
        $worked = $g('work');
        $absent = $g('absent_excused') + $g('absent_unexcused');
        $attendance = ($worked + $absent) > 0 ? $worked * 100 / ($worked + $absent) : 0;

        $mins = $range()->where('type', 'work')->whereNotNull('start_time')->whereNotNull('end_time')->get(['start_time', 'end_time'])->map(function ($r) {
            $d = (strtotime((string) $r->end_time) - strtotime((string) $r->start_time)) / 60;

            return $d < 0 ? $d + 1440 : $d;
        });
        $avgHours = $mins->count() ? $mins->avg() / 60 : null;

        $daily = $range()->selectRaw(
            "DATE(work_date) d, SUM(CASE WHEN type = 'work' THEN 1 ELSE 0 END) work,
             SUM(CASE WHEN type IN ('vacation','sick','off') THEN 1 ELSE 0 END) rest,
             SUM(CASE WHEN type IN ('absent_excused','absent_unexcused') THEN 1 ELSE 0 END) absent"
        )->groupBy(DB::raw('DATE(work_date)'))->get();
        $planLabels = ['work' => 'Ishlaydi', 'off' => 'Dam oladi', 'vacation' => 'Ta‘til', 'sick' => 'Kasallik', 'absent_excused' => 'Sababli kelmagan', 'absent_unexcused' => 'Sababsiz kelmagan'];
        $byType = $range()->selectRaw('type, COUNT(*) total')->groupBy('type')->orderByDesc('total')->get();
        $emp = fn () => $range()->join('employees', 'employees.id', '=', 'work_schedules.employee_id')->join('users', 'users.id', '=', 'employees.user_id');
        $unex = $emp()->where('work_schedules.type', 'absent_unexcused')->selectRaw('users.name, COUNT(*) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        $topWork = $emp()->where('work_schedules.type', 'work')->selectRaw('users.name, COUNT(*) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        $absRows = $emp()->whereIn('work_schedules.type', ['absent_excused', 'absent_unexcused'])->orderByDesc('work_schedules.work_date')->limit(7)->get(['users.name', 'work_schedules.work_date', 'work_schedules.type']);

        return $this->respond('Ish jadvali statistikasi', [
            $this->kpi('Bugun ishda', (int) ($todayRows['work'] ?? 0), 'int', 'dam/ta‘til/kasal: '.(int) $todayRows->except('work')->sum(), 'emerald', 'users'),
            $this->kpi('Davomat', round($attendance, 1), 'pct', 'ish kunlari / (ish + kelmagan)', 'blue', 'activity'),
            $this->kpi('Sababsiz kelmagan', $g('absent_unexcused'), 'int', '30 kun, kunlar', 'rose', 'alert'),
            $this->kpi('Sababli kelmagan', $g('absent_excused'), 'int', '30 kun, kunlar', 'amber', 'clock'),
            $this->kpi('Ta‘til va kasallik', $g('vacation') + $g('sick'), 'int', '30 kun, kunlar', 'violet', 'file'),
            $this->kpi('O‘rtacha ish soati', $avgHours === null ? '—' : round($avgHours, 1).' soat', 'text', 'vaqti belgilangan smenalar', 'cyan', 'clock'),
        ], [
            $this->chart('bars', 'Kunlik ish rejasi', $this->dailySeries($daily, ['work', 'rest', 'absent']), [
                'format' => 'int', 'wide' => true, 'hint' => 'Kunlar bo‘yicha xodimlar soni',
                'series' => [['key' => 'work', 'name' => 'Ishlaydi', 'color' => '#10b981'], ['key' => 'rest', 'name' => 'Dam / ta‘til / kasal', 'color' => '#f59e0b'], ['key' => 'absent', 'name' => 'Kelmagan', 'color' => '#f43f5e']],
            ]),
            $this->chart('donut', 'Jadval turlari', $this->lv($byType, 'type', 'total', fn ($k) => $planLabels[$k] ?? $this->nice($k)), ['format' => 'int', 'hint' => '30 kun, kunlar soni']),
            $this->chart('hbar', 'Eng ko‘p ishlaganlar', $this->lv($topWork), ['format' => 'int', 'hint' => 'Ish kunlari soni', 'color' => '#10b981']),
            $this->chart('hbar', 'Sababsiz kelmaganlar', $this->lv($unex), ['format' => 'int', 'hint' => 'Kunlar soni', 'color' => '#f43f5e']),
            $this->chart('table', 'So‘nggi kelmaslik holatlari', collect($absRows)->map(fn ($r) => ['name' => $r->name, 'date' => substr((string) $r->work_date, 0, 10), 'type' => $planLabels[$r->type] ?? $this->nice($r->type)])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Xodim'], ['key' => 'date', 'label' => 'Sana'], ['key' => 'type', 'label' => 'Turi']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ ROLLAR VA RUXSATLAR

    public function roles(Request $request)
    {
        $roles = DB::table('roles')->selectRaw(
            'roles.name, (SELECT COUNT(*) FROM permission_role pr WHERE pr.role_id = roles.id) perms, (SELECT COUNT(*) FROM role_user ru WHERE ru.role_id = roles.id) members'
        )->orderByDesc('perms')->get();
        $permTotal = DB::table('permissions')->count();
        $assigned = DB::table('role_user')->distinct()->count('user_id');
        $noRole = DB::table('users')->where('status', 'active')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('role_user')->whereColumn('role_user.user_id', 'users.id'))->count();

        $labels = [
            'products' => 'Mahsulotlar', 'sales' => 'Sotuvlar', 'customers' => 'Mijozlar', 'suppliers' => 'Yetkazib beruvchilar', 'warehouses' => 'Ombor', 'purchases' => 'Xaridlar',
            'cashboxes' => 'Kassalar', 'bank' => 'Bank', 'expenses' => 'Xarajatlar', 'shifts' => 'Smenalar', 'employees' => 'Xodimlar', 'users' => 'Foydalanuvchilar',
            'roles' => 'Rollar', 'reports' => 'Hisobotlar', 'audit' => 'Audit', 'branches' => 'Filiallar',
        ];
        $mod = fn ($slug) => explode('.', (string) $slug)[0];
        $perModule = DB::table('permissions')->pluck('slug')->groupBy($mod)->map->count()->sortDesc()->take(10);
        $coverage = DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->get(['permission_role.role_id', 'permissions.slug'])
            ->groupBy(fn ($r) => $mod($r->slug))->map(fn ($g) => $g->pluck('role_id')->unique()->count())->sortDesc()->take(10);
        $rows = fn ($c) => $c->map(fn ($v, $k) => ['label' => $labels[$k] ?? $this->nice($k), 'value' => (float) $v])->values()->all();
        $top = $roles->first();

        return $this->respond('Rollar va ruxsatlar statistikasi', [
            $this->kpi('Rollar', $roles->count(), 'int', null, 'blue', 'users'),
            $this->kpi('Ruxsatlar', $permTotal, 'int', 'tizimdagi jami', 'violet', 'tag'),
            $this->kpi('Rol berilgan hisoblar', $assigned, 'int', null, 'emerald', 'users'),
            $this->kpi('Rolsiz faol hisoblar', $noRole, 'int', 'tizimda hech narsa ko‘rmaydi', 'rose', 'alert'),
            $this->kpi('O‘rtacha ruxsat', $roles->count() ? round((float) $roles->avg('perms'), 1) : 0, 'pct', 'har bir rolga (% belgisiz)', 'cyan', 'activity'),
            $this->kpi('Eng keng ruxsatli rol', $top->name ?? '—', 'text', $top ? ((int) $top->perms).' ta ruxsat' : null, 'amber', 'alert'),
        ], [
            $this->chart('hbar', 'Rollardagi ruxsatlar', $this->lv($roles->take(8)->values(), 'name', 'perms'), ['format' => 'int', 'color' => '#8b5cf6']),
            $this->chart('donut', 'Foydalanuvchilar rollar bo‘yicha', $this->lv($roles->filter(fn ($r) => (int) $r->members > 0)->values(), 'name', 'members'), ['format' => 'int']),
            $this->chart('hbar', 'Modullar bo‘yicha ruxsatlar', $rows($perModule), ['format' => 'int', 'hint' => 'Har bir modulda nechta ruxsat', 'color' => '#2563eb']),
            $this->chart('hbar', 'Modul qamrovi', $rows($coverage), ['format' => 'int', 'hint' => 'Modul nechta rolda mavjud', 'color' => '#10b981']),
            $this->chart('table', 'Rollar ro‘yxati', $roles->take(8)->map(fn ($r) => ['name' => $r->name, 'perms' => (int) $r->perms, 'members' => (int) $r->members])->values()->all(), [
                'columns' => [['key' => 'name', 'label' => 'Rol'], ['key' => 'perms', 'label' => 'Ruxsat', 'format' => 'int'], ['key' => 'members', 'label' => 'Foydalanuvchi', 'format' => 'int']],
            ]),
        ], 'Joriy holat');
    }

    // ------------------------------------------------------------------ AUDIT JURNALI

    public function audit(Request $request)
    {
        $from = $this->from()->toDateString();
        $to = Carbon::today()->toDateString();
        $log = fn () => DB::table('audit_logs')->whereDate('audit_logs.created_at', '>=', $from);
        $t = $log()->selectRaw("COUNT(*) c, COALESCE(SUM(CASE WHEN result = 'failed' THEN 1 ELSE 0 END),0) f, COUNT(DISTINCT user_id) u, COUNT(DISTINCT ip_address) ips")->first();
        $today = $log()->whereDate('audit_logs.created_at', $to)->count();
        $daily = $log()->selectRaw("DATE(created_at) d, SUM(CASE WHEN result <> 'failed' THEN 1 ELSE 0 END) ok, SUM(CASE WHEN result = 'failed' THEN 1 ELSE 0 END) failed")->groupBy(DB::raw('DATE(created_at)'))->get();
        $actions = $log()->selectRaw('action, COUNT(*) total')->groupBy('action')->orderByDesc('total')->limit(8)->get();
        $users = $log()->join('users', 'users.id', '=', 'audit_logs.user_id')->selectRaw('users.name, COUNT(*) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(8)->get();
        $hours = $log()->selectRaw($this->hourExpr('audit_logs.created_at').' h, COUNT(*) value')->groupBy(DB::raw($this->hourExpr('audit_logs.created_at')))->get()->keyBy(fn ($r) => (int) $r->h);
        $hourRows = [];
        for ($h = 0; $h < 24; $h++) {
            $hourRows[] = ['label' => sprintf('%02d', $h), 'value' => (float) ($hours[$h]->value ?? 0)];
        }
        $failedRows = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.result', 'failed')->orderByDesc('audit_logs.id')->limit(7)
            ->get(['audit_logs.action', 'users.name', 'audit_logs.created_at']);
        $failRate = (int) $t->c ? ((int) $t->f) * 100 / (int) $t->c : 0;
        $topAction = $actions->first();

        return $this->respond('Audit statistikasi', [
            $this->kpi('30 kunlik amallar', (int) $t->c, 'int', 'bugun: '.$today, 'blue', 'activity'),
            $this->kpi('Muvaffaqiyatsiz', (int) $t->f, 'int', round($failRate, 1).'% amallar', 'rose', 'alert'),
            $this->kpi('Faol foydalanuvchilar', (int) $t->u, 'int', 'amal bajarganlar', 'emerald', 'users'),
            $this->kpi('Noyob IP manzillar', (int) $t->ips, 'int', null, 'violet', 'tag'),
            $this->kpi('Eng ko‘p amal', $topAction ? $this->nice($topAction->action) : '—', 'text', $topAction ? ((int) $topAction->total).' marta' : null, 'amber', 'file'),
        ], [
            $this->chart('bars', 'Kunlik amallar', $this->dailySeries($daily, ['ok', 'failed']), [
                'format' => 'int', 'wide' => true, 'hint' => 'Muvaffaqiyatli va muvaffaqiyatsiz',
                'series' => [['key' => 'ok', 'name' => 'Muvaffaqiyatli', 'color' => '#10b981'], ['key' => 'failed', 'name' => 'Muvaffaqiyatsiz', 'color' => '#f43f5e']],
            ]),
            $this->chart('donut', 'Natija', [['label' => 'Muvaffaqiyatli', 'value' => (float) ((int) $t->c - (int) $t->f)], ['label' => 'Muvaffaqiyatsiz', 'value' => (float) $t->f]], ['format' => 'int']),
            $this->chart('hbar', 'Eng ko‘p amallar', $this->lv($actions, 'action', 'total', fn ($k) => $this->nice($k)), ['format' => 'int', 'color' => '#6366f1']),
            $this->chart('hbar', 'Foydalanuvchilar faolligi', $this->lv($users), ['format' => 'int', 'color' => '#06b6d4']),
            $this->chart('bars', 'Kun davomida faollik', $hourRows, ['format' => 'int', 'wide' => true, 'hint' => 'Soatlar bo‘yicha amallar', 'color' => '#8b5cf6']),
            $this->chart('table', 'So‘nggi muvaffaqiyatsiz amallar', collect($failedRows)->map(fn ($r) => ['name' => $this->nice($r->action), 'user' => $r->name ?? '—', 'at' => substr((string) $r->created_at, 0, 16)])->all(), [
                'columns' => [['key' => 'name', 'label' => 'Amal'], ['key' => 'user', 'label' => 'Foydalanuvchi'], ['key' => 'at', 'label' => 'Vaqt']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ HISOBOTLAR (moliyaviy xulosa)

    public function reports(Request $request)
    {
        $from = $this->from()->toDateString();
        $to = Carbon::today()->toDateString();
        $sign = "(CASE WHEN sales.type = 'return' THEN -1 ELSE 1 END)";
        $items = fn () => DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.status', 'approved');
        $profitExpr = "SUM($sign * (sale_items.net_total - sale_items.quantity * sale_items.unit_cost))";
        $period = fn (string $a, string $b) => $items()->whereDate('sales.sold_at', '>=', $a)->whereDate('sales.sold_at', '<=', $b);
        $exp = fn (string $a, string $b) => (float) DB::table('expenses')->where('status', 'approved')->whereDate('expense_date', '>=', $a)->whereDate('expense_date', '<=', $b)->sum('amount');
        $buy = fn (string $a, string $b) => (float) DB::table('stock_documents')->where('type', 'purchase')->where('status', 'approved')->whereDate('doc_date', '>=', $a)->whereDate('doc_date', '<=', $b)->sum('total');

        $tot = $period($from, $to)->selectRaw("COALESCE(SUM($sign * sale_items.net_total),0) rev, COALESCE(SUM($sign * sale_items.quantity * sale_items.unit_cost),0) cogs")->first();
        $rev = (float) $tot->rev;
        $cogs = (float) $tot->cogs;
        $gross = $rev - $cogs;
        $exp30 = $exp($from, $to);
        $buy30 = $buy($from, $to);
        $net = $gross - $exp30;

        $daily = $period($from, $to)->selectRaw("DATE(sales.sold_at) d, COALESCE(SUM($sign * sale_items.net_total),0) revenue, COALESCE($profitExpr,0) profit")->groupBy(DB::raw('DATE(sales.sold_at)'))->get();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $s = Carbon::today()->startOfMonth()->subMonths($i);
            [$a, $b] = [$s->toDateString(), $s->copy()->endOfMonth()->toDateString()];
            $months[] = [
                'label' => $s->format('m.Y'),
                'revenue' => (float) $period($a, $b)->sum(DB::raw("$sign * sale_items.net_total")),
                'purchases' => $buy($a, $b),
                'expenses' => $exp($a, $b),
            ];
        }
        $byCat = $period($from, $to)->join('products', 'products.id', '=', 'sale_items.product_id')->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw("COALESCE(categories.name, 'Kategoriyasiz') name, COALESCE($profitExpr,0) total")->groupBy('categories.id', 'categories.name')->orderByDesc('total')->limit(8)->get();
        $byProd = $period($from, $to)->join('products', 'products.id', '=', 'sale_items.product_id')
            ->selectRaw("products.name, COALESCE($profitExpr,0) total")->groupBy('products.id', 'products.name')->orderByDesc('total')->limit(8)->get();

        return $this->respond('Moliyaviy xulosa', [
            $this->kpi('Sof savdo', $this->m($rev), 'money', 'qaytarishlar ayirilgan', 'blue', 'shopping'),
            $this->kpi('Yalpi foyda', $this->m($gross), 'money', 'savdo − tannarx', 'emerald', 'trend'),
            $this->kpi('Foyda marjasi', $rev > 0 ? round($gross * 100 / $rev, 1) : 0, 'pct', 'yalpi foyda / savdo', 'violet', 'activity'),
            $this->kpi('Xarajatlar', $this->m($exp30), 'money', 'tasdiqlangan', 'rose', 'banknote'),
            $this->kpi('Sof foyda', $this->m($net), 'money', 'yalpi foyda − xarajat', $net >= 0 ? 'cyan' : 'orange', 'wallet'),
            $this->kpi('Xaridlar', $this->m($buy30), 'money', 'tasdiqlangan, 30 kun', 'amber', 'truck'),
        ], [
            $this->chart('bars', 'Savdo va foyda', $this->dailySeries($daily, ['revenue', 'profit']), [
                'format' => 'money', 'wide' => true, 'hint' => 'Kunlik sof savdo va yalpi foyda',
                'series' => [['key' => 'revenue', 'name' => 'Savdo', 'color' => '#2563eb'], ['key' => 'profit', 'name' => 'Yalpi foyda', 'color' => '#10b981']],
            ]),
            $this->chart('donut', 'Savdo tuzilmasi', [['label' => 'Tannarx', 'value' => max(0, $cogs)], ['label' => 'Xarajatlar', 'value' => max(0, $exp30)], ['label' => 'Sof foyda', 'value' => max(0, $net)]], ['format' => 'money', 'hint' => 'Savdo nimalarga ketdi']),
            $this->chart('bars', 'Oylik dinamika', $months, [
                'format' => 'money', 'wide' => true, 'hint' => 'So‘nggi 6 oy: savdo, xarid, xarajat',
                'series' => [['key' => 'revenue', 'name' => 'Savdo', 'color' => '#2563eb'], ['key' => 'purchases', 'name' => 'Xarid', 'color' => '#f59e0b'], ['key' => 'expenses', 'name' => 'Xarajat', 'color' => '#f43f5e']],
            ]),
            $this->chart('hbar', 'Kategoriyalar foydasi', $this->lv($byCat), ['format' => 'money', 'hint' => '30 kun', 'color' => '#10b981']),
            $this->chart('hbar', 'Eng foydali mahsulotlar', $this->lv($byProd), ['format' => 'money', 'hint' => '30 kun', 'color' => '#8b5cf6']),
        ]);
    }

    // ------------------------------------------------------------------ EXCEL IMPORT

    public function imports(Request $request)
    {
        $from = $this->from()->toDateString();
        $typeLabels = ['products' => 'Mahsulotlar', 'customers' => 'Mijozlar', 'opening_stock' => 'Boshlang‘ich qoldiq'];
        $rows = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->where('audit_logs.action', 'imports.commit')
            ->whereDate('audit_logs.created_at', '>=', $from)->orderByDesc('audit_logs.id')->get(['audit_logs.result', 'audit_logs.new_values', 'audit_logs.created_at', 'users.name as user']);

        $ok = 0;
        $failed = 0;
        $created = 0;
        $skipped = 0;
        $byType = [];
        $createdByType = [];
        $byUser = [];
        $perDay = [];
        $recent = [];
        foreach ($rows as $r) {
            $v = is_string($r->new_values) ? (json_decode($r->new_values, true) ?: []) : (array) $r->new_values;
            $day = substr((string) $r->created_at, 0, 10);
            $perDay[$day] ??= (object) ['d' => $day, 'ok' => 0, 'failed' => 0];
            if ($r->result === 'failed') {
                $failed++;
                $perDay[$day]->failed++;
                continue;
            }
            $ok++;
            $perDay[$day]->ok++;
            $t = (string) ($v['type'] ?? '—');
            $byType[$t] = ($byType[$t] ?? 0) + 1;
            $createdByType[$t] = ($createdByType[$t] ?? 0) + (int) ($v['created'] ?? 0);
            $created += (int) ($v['created'] ?? 0);
            $skipped += (int) ($v['skipped'] ?? 0);
            $u = $r->user ?: '—';
            $byUser[$u] = ($byUser[$u] ?? 0) + 1;
            if (count($recent) < 7) {
                $recent[] = ['name' => ($typeLabels[$t] ?? $this->nice($t)).' · '.($v['file'] ?? '—'), 'date' => substr((string) $r->created_at, 0, 10), 'created' => (int) ($v['created'] ?? 0)];
            }
        }
        $toRows = fn (array $a, ?callable $fn = null) => array_map(fn ($k, $v) => ['label' => $fn ? $fn((string) $k) : (string) $k, 'value' => (float) $v], array_keys($a), array_values($a));
        arsort($byType);
        arsort($createdByType);
        arsort($byUser);
        $topType = array_key_first($byType);
        $lastAt = $rows->first()->created_at ?? null;

        return $this->respond('Import statistikasi', [
            $this->kpi('Muvaffaqiyatli importlar', $ok, 'int', '30 kun', 'emerald', 'file'),
            $this->kpi('Muvaffaqiyatsiz urinishlar', $failed, 'int', 'xato bilan to‘xtagan', 'rose', 'alert'),
            $this->kpi('Yaratilgan yozuvlar', $created, 'int', 'importlar orqali', 'blue', 'package'),
            $this->kpi('O‘tkazib yuborilgan', $skipped, 'int', 'qator', 'amber', 'clock'),
            $this->kpi('Eng ko‘p import turi', $topType !== null ? ($typeLabels[$topType] ?? $this->nice($topType)) : '—', 'text', $topType !== null ? $byType[$topType].' marta' : null, 'violet', 'tag'),
            $this->kpi('Oxirgi import', $lastAt ? substr((string) $lastAt, 0, 16) : '—', 'text', null, 'cyan', 'clock'),
        ], [
            $this->chart('bars', 'Kunlik importlar', $this->dailySeries(array_values($perDay), ['ok', 'failed']), [
                'format' => 'int', 'wide' => true, 'hint' => 'Muvaffaqiyatli va muvaffaqiyatsiz',
                'series' => [['key' => 'ok', 'name' => 'Muvaffaqiyatli', 'color' => '#10b981'], ['key' => 'failed', 'name' => 'Muvaffaqiyatsiz', 'color' => '#f43f5e']],
            ]),
            $this->chart('donut', 'Import turlari', $toRows($byType, fn ($k) => $typeLabels[$k] ?? $this->nice($k)), ['format' => 'int']),
            $this->chart('hbar', 'Yaratilgan yozuvlar', $toRows($createdByType, fn ($k) => $typeLabels[$k] ?? $this->nice($k)), ['format' => 'int', 'hint' => 'Tur bo‘yicha', 'color' => '#2563eb']),
            $this->chart('hbar', 'Kim import qilgan', array_slice($toRows($byUser), 0, 8), ['format' => 'int', 'color' => '#06b6d4']),
            $this->chart('table', 'So‘nggi importlar', $recent, [
                'columns' => [['key' => 'name', 'label' => 'Import'], ['key' => 'date', 'label' => 'Sana'], ['key' => 'created', 'label' => 'Yaratildi', 'format' => 'int']],
            ]),
        ]);
    }

    // ------------------------------------------------------------------ BIRINCHI ISHGA TUSHIRISH

    public function onboarding(Request $request)
    {
        $steps = ['store' => 'Do‘kon ma‘lumotlari', 'branch' => 'Filial yaratish', 'warehouse' => 'Ombor yaratish', 'first_product' => 'Birinchi mahsulot'];
        $done = DB::table('erp_onboarding')->whereIn('step_key', array_keys($steps))->where('is_completed', true)->get()->keyBy('step_key');
        $live = fn (string $table) => DB::table($table)->when(in_array($table, ['products'], true), fn ($q) => $q->whereNull('deleted_at'))->count();
        $counts = [
            'Filiallar' => $live('branches'), 'Omborlar' => $live('warehouses'), 'Kategoriyalar' => $live('categories'), 'Mahsulotlar' => $live('products'),
            'Mijozlar' => $live('customers'), 'Yetkazib beruvchilar' => $live('suppliers'), 'Foydalanuvchilar' => $live('users'), 'Xodimlar' => $live('employees'),
            'Kassalar' => $live('cashboxes'), 'Bank hisoblari' => $live('bank_accounts'),
        ];
        $filled = count(array_filter($counts));
        $realData = ['branch' => $counts['Filiallar'].' ta filial', 'warehouse' => $counts['Omborlar'].' ta ombor', 'first_product' => $counts['Mahsulotlar'].' ta mahsulot', 'store' => '—'];
        $readyCount = $done->count();

        return $this->respond('Ishga tushirish holati', [
            $this->kpi('Bosqichlar', $readyCount.' / '.count($steps), 'text', $readyCount === count($steps) ? 'hammasi bajarilgan' : (count($steps) - $readyCount).' ta qoldi', $readyCount === count($steps) ? 'emerald' : 'amber', 'activity'),
            $this->kpi('Mahsulotlar', $counts['Mahsulotlar'], 'int', $counts['Kategoriyalar'].' ta kategoriya', 'blue', 'package'),
            $this->kpi('Omborlar', $counts['Omborlar'], 'int', $counts['Filiallar'].' ta filial', 'violet', 'boxes'),
            $this->kpi('Foydalanuvchilar', $counts['Foydalanuvchilar'], 'int', $counts['Xodimlar'].' ta xodim kartochkasi', 'cyan', 'users'),
            $this->kpi('Kassalar va bank', $counts['Kassalar'] + $counts['Bank hisoblari'], 'int', $counts['Kassalar'].' kassa · '.$counts['Bank hisoblari'].' bank hisobi', 'emerald', 'wallet'),
            $this->kpi('To‘ldirilgan bo‘limlar', $filled.' / '.count($counts), 'text', 'kamida bitta yozuvi bor', 'slate', 'trend'),
        ], [
            $this->chart('donut', 'Bosqichlar holati', [['label' => 'Bajarilgan', 'value' => (float) $readyCount], ['label' => 'Qolgan', 'value' => (float) (count($steps) - $readyCount)]], ['format' => 'int']),
            $this->chart('hbar', 'Tizim to‘ldirilganligi', array_map(fn ($k, $v) => ['label' => $k, 'value' => (float) $v], array_keys($counts), array_values($counts)), ['format' => 'int', 'hint' => 'Bo‘limlardagi yozuvlar soni', 'color' => '#2563eb']),
            $this->chart('table', 'Tayyorlik ro‘yxati', collect($steps)->map(fn ($label, $key) => [
                'name' => $label, 'state' => isset($done[$key]) ? 'Bajarilgan' : 'Kutilmoqda', 'data' => $realData[$key] ?? '—',
            ])->values()->all(), ['columns' => [['key' => 'name', 'label' => 'Bosqich'], ['key' => 'state', 'label' => 'Holat'], ['key' => 'data', 'label' => 'Tizimda']]]),
        ], 'Joriy holat');
    }
}
