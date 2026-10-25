<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rolga qarab to‘ladigan boshqaruv paneli ma‘lumotlari (GET /dashboard/me).
 *
 * Har bir blok faqat foydalanuvchida tegishli ruxsat bo‘lsa qaytariladi, shuning uchun
 * kassir, omborchi, buxgalter, menejer yoki istalgan maxsus rol o‘ziga mos panelni oladi.
 *  - personal: foydalanuvchining o‘z harakatlari (sotuvlari, smenasi, hujjatlari, jurnali)
 *  - overview: umumiy holat (savdo, ombor, xaridlar, moliya, mijozlar, jamoa)
 */
class DashboardController extends Controller
{
    private const DAYS = 14;

    public function me(Request $request)
    {
        $u = $request->user();
        $can = fn (string $p) => $u->can($p);
        $today = Carbon::today();

        $payload = [
            'user' => [
                'id' => $u->id,
                'name' => $u->name,
                'roles' => $u->roles()->get(['roles.id', 'roles.name', 'roles.slug'])->map(fn ($r) => ['name' => $r->name, 'slug' => $r->slug])->all(),
            ],
            'generated_at' => now()->toIso8601String(),
            'personal' => ['actions' => $this->actions($u->id)],
            'overview' => [],
        ];

        $sellsOrSees = $can('sales.view') || $can('sales.create');
        if ($sellsOrSees) {
            $payload['personal']['sales'] = $this->personalSales($u->id, $today);
        }
        if ($can('shifts.view') || $can('shifts.create')) {
            $payload['personal']['shift'] = $this->personalShift($u->id);
        }
        if ($can('purchases.view') || $can('warehouses.view')) {
            $payload['personal']['documents'] = $this->personalDocuments($u->id, $today);
        }
        if ($can('expenses.view') || $can('expenses.create')) {
            $payload['personal']['expenses'] = $this->personalExpenses($u->id, $today);
        }

        if ($can('sales.view')) {
            $payload['overview']['sales'] = $this->overviewSales($today);
        }
        if ($can('products.view') || $can('warehouses.view')) {
            $payload['overview']['stock'] = $this->overviewStock($can('warehouses.view'));
        }
        if ($can('purchases.view')) {
            $payload['overview']['purchases'] = $this->overviewPurchases($today);
        }
        if ($can('customers.view')) {
            $payload['overview']['customers'] = $this->overviewCustomers();
        }
        $finance = $this->overviewFinance($today, $can('cashboxes.view'), $can('bank.view'), $can('expenses.view'));
        if ($finance) {
            $payload['overview']['finance'] = $finance;
        }
        if ($can('shifts.view')) {
            $payload['overview']['shifts'] = [
                'open' => DB::table('cashbox_shifts')->where('status', 'open')->count(),
                'to_review' => $can('shifts.approve') ? DB::table('cashbox_shifts')->where('status', 'closed')->whereNull('reviewed_at')->where('difference', '!=', 0)->count() : null,
            ];
        }
        if ($can('users.view') || $can('employees.view')) {
            $payload['overview']['team'] = [
                'users' => $can('users.view') ? DB::table('users')->where('status', 'active')->count() : null,
                'employees' => $can('employees.view') ? DB::table('employees')->count() : null,
            ];
        }

        return response()->json($payload);
    }

    // ---------------------------------------------------------------- yordamchilar

    private function m($v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    /** Oxirgi N kun uchun bo‘sh kunlar ham to‘ldirilgan qator. */
    private function fillDays($rows, Carbon $today, array $keys): array
    {
        $by = [];
        foreach ($rows as $r) {
            $by[substr((string) $r->d, 0, 10)] = $r;
        }
        $out = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i)->toDateString();
            $row = ['date' => $d];
            foreach ($keys as $k) {
                $row[$k] = isset($by[$d]) ? (float) $by[$d]->$k : 0;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function salesRange(Carbon $from, Carbon $to, ?int $userId = null)
    {
        return DB::table('sales')->where('status', 'approved')->where('type', 'sale')
            ->whereDate('sold_at', '>=', $from->toDateString())->whereDate('sold_at', '<=', $to->toDateString())
            ->when($userId, fn ($q) => $q->where('created_by', $userId));
    }

    private function totals(Carbon $from, Carbon $to, ?int $userId = null): array
    {
        $r = $this->salesRange($from, $to, $userId)->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t, COALESCE(SUM(debt_amount),0) d')->first();

        return ['count' => (int) $r->c, 'total' => $this->m($r->t), 'debt' => $this->m($r->d)];
    }

    private function series(Carbon $today, ?int $userId = null): array
    {
        $rows = $this->salesRange($today->copy()->subDays(self::DAYS - 1), $today, $userId)
            ->selectRaw('DATE(sold_at) d, COUNT(*) count, COALESCE(SUM(total),0) total')->groupBy(DB::raw('DATE(sold_at)'))->get();

        return $this->fillDays($rows, $today, ['total', 'count']);
    }

    private function byMethod(Carbon $from, Carbon $to, ?int $userId = null): array
    {
        return DB::table('payments')->where('direction', 'in')
            ->whereDate('created_at', '>=', $from->toDateString())->whereDate('created_at', '<=', $to->toDateString())
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->selectRaw('method, COALESCE(SUM(amount),0) total')->groupBy('method')->orderByDesc('total')->get()
            ->map(fn ($r) => ['method' => $r->method, 'total' => (float) $r->total])->all();
    }

    private function topProducts(Carbon $from, Carbon $to, ?int $userId = null, int $limit = 6): array
    {
        return DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')
            ->whereDate('sales.sold_at', '>=', $from->toDateString())->whereDate('sales.sold_at', '<=', $to->toDateString())
            ->when($userId, fn ($q) => $q->where('sales.created_by', $userId))
            ->selectRaw('products.id, products.name, COALESCE(SUM(sale_items.quantity),0) quantity, COALESCE(SUM(sale_items.net_total),0) total')
            ->groupBy('products.id', 'products.name')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'quantity' => (float) $r->quantity, 'total' => (float) $r->total])->all();
    }

    // ---------------------------------------------------------------- shaxsiy blok

    private function actions(int $userId): array
    {
        return DB::table('audit_logs')->where('user_id', $userId)->orderByDesc('id')->limit(10)
            ->get(['id', 'action', 'auditable_type', 'auditable_id', 'result', 'created_at'])
            ->map(fn ($r) => [
                'id' => $r->id, 'action' => $r->action, 'entity' => $r->auditable_type ? class_basename($r->auditable_type) : null,
                'entity_id' => $r->auditable_id, 'result' => $r->result, 'at' => $r->created_at,
            ])->all();
    }

    private function personalSales(int $userId, Carbon $today): array
    {
        $week = $today->copy()->subDays(6);
        $month = $today->copy()->startOfMonth();
        $monthTotals = $this->totals($month, $today, $userId);

        return [
            'today' => $this->totals($today, $today, $userId),
            'week' => $this->totals($week, $today, $userId),
            'month' => $monthTotals + ['avg_check' => $this->m($monthTotals['count'] ? ((float) $monthTotals['total']) / $monthTotals['count'] : 0)],
            'returns_month' => (int) DB::table('sales')->where('status', 'approved')->where('type', 'return')->where('created_by', $userId)
                ->whereDate('sold_at', '>=', $month->toDateString())->count(),
            'series' => $this->series($today, $userId),
            'by_method' => $this->byMethod($month, $today, $userId),
            'top_products' => $this->topProducts($month, $today, $userId),
            'recent' => DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
                ->where('sales.created_by', $userId)->orderByDesc('sales.id')->limit(8)
                ->get(['sales.id', 'sales.number', 'sales.type', 'sales.status', 'sales.total', 'sales.debt_amount', 'sales.sold_at', 'customers.name as customer'])
                ->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function personalShift(int $userId): array
    {
        $open = DB::table('cashbox_shifts')->join('cashboxes', 'cashboxes.id', '=', 'cashbox_shifts.cashbox_id')
            ->where('cashbox_shifts.user_id', $userId)->where('cashbox_shifts.status', 'open')->orderByDesc('cashbox_shifts.id')
            ->first(['cashbox_shifts.id', 'cashbox_shifts.opened_at', 'cashbox_shifts.opening_cash', 'cashboxes.name as cashbox']);
        $current = null;
        if ($open) {
            $s = DB::table('sales')->where('shift_id', $open->id)->where('status', 'approved')->where('type', 'sale')
                ->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t')->first();
            $current = [
                'id' => $open->id, 'cashbox' => $open->cashbox, 'opened_at' => $open->opened_at, 'opening_cash' => $this->m($open->opening_cash),
                'sales_count' => (int) $s->c, 'sales_total' => $this->m($s->t),
            ];
        }
        $recent = DB::table('cashbox_shifts')->join('cashboxes', 'cashboxes.id', '=', 'cashbox_shifts.cashbox_id')
            ->where('cashbox_shifts.user_id', $userId)->orderByDesc('cashbox_shifts.id')->limit(6)
            ->get(['cashbox_shifts.id', 'cashbox_shifts.status', 'cashbox_shifts.opened_at', 'cashbox_shifts.closed_at', 'cashbox_shifts.difference', 'cashboxes.name as cashbox'])
            ->map(fn ($r) => (array) $r)->all();

        return ['open' => $current, 'recent' => $recent];
    }

    private function personalDocuments(int $userId, Carbon $today): array
    {
        $month = $today->copy()->startOfMonth()->toDateString();
        $q = fn () => DB::table('stock_documents')->where('created_by', $userId)->whereDate('doc_date', '>=', $month);

        return [
            'month_count' => $q()->count(),
            'by_type' => $q()->selectRaw('type, COUNT(*) count')->groupBy('type')->orderByDesc('count')->get()->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->count])->all(),
            'recent' => DB::table('stock_documents')->where('created_by', $userId)->orderByDesc('id')->limit(6)
                ->get(['id', 'number', 'type', 'status', 'total', 'doc_date'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function personalExpenses(int $userId, Carbon $today): array
    {
        $month = $today->copy()->startOfMonth()->toDateString();
        $q = fn () => DB::table('expenses')->where('created_by', $userId)->whereDate('expense_date', '>=', $month);

        return [
            'month_count' => $q()->count(),
            'month_total' => $this->m($q()->sum('amount')),
            'pending' => DB::table('expenses')->where('created_by', $userId)->where('status', 'pending')->count(),
        ];
    }

    // ---------------------------------------------------------------- umumiy holat

    private function overviewSales(Carbon $today): array
    {
        $yesterday = $today->copy()->subDay();

        return [
            'today' => $this->totals($today, $today),
            'yesterday' => $this->totals($yesterday, $yesterday),
            'week' => $this->totals($today->copy()->subDays(6), $today),
            'month' => $this->totals($today->copy()->startOfMonth(), $today),
            'series' => $this->series($today),
            'by_method' => $this->byMethod($today->copy()->startOfMonth(), $today),
            'top_products' => $this->topProducts($today->copy()->subDays(29), $today),
            'by_cashier' => DB::table('sales')->join('users', 'users.id', '=', 'sales.created_by')->where('sales.status', 'approved')->where('sales.type', 'sale')
                ->whereDate('sales.sold_at', '>=', $today->copy()->startOfMonth()->toDateString())
                ->selectRaw('users.name, COUNT(*) count, COALESCE(SUM(sales.total),0) total')->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(6)->get()
                ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count, 'total' => (float) $r->total])->all(),
        ];
    }

    private function overviewStock(bool $withWarehouses): array
    {
        $low = DB::table('products')->leftJoin('warehouse_stocks', 'warehouse_stocks.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')->where('products.is_active', true)
            ->selectRaw('products.id, products.name, products.sku, products.min_stock, COALESCE(SUM(warehouse_stocks.quantity),0) quantity')
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.min_stock')
            ->havingRaw('COALESCE(SUM(warehouse_stocks.quantity),0) <= products.min_stock');
        $lowCount = DB::query()->fromSub(clone $low, 'l')->count();
        $out = [
            'products' => DB::table('products')->whereNull('deleted_at')->where('is_active', true)->count(),
            'low_count' => $lowCount,
            'low' => (clone $low)->orderByRaw('COALESCE(SUM(warehouse_stocks.quantity),0) ASC')->limit(8)->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'sku' => $r->sku, 'quantity' => (float) $r->quantity, 'min_stock' => (float) $r->min_stock])->all(),
        ];
        if ($withWarehouses) {
            $out['warehouses'] = DB::table('warehouses')->count();
            $out['by_warehouse'] = DB::table('warehouses')->leftJoin('warehouse_stocks', function ($j) {
                $j->on('warehouse_stocks.warehouse_id', '=', 'warehouses.id')->where('warehouse_stocks.quantity', '>', 0);
            })->selectRaw('warehouses.name, COUNT(warehouse_stocks.id) positions')->groupBy('warehouses.id', 'warehouses.name')->orderByDesc('positions')->limit(8)->get()
                ->map(fn ($r) => ['name' => $r->name, 'positions' => (int) $r->positions])->all();
            $out['movements'] = DB::table('stock_movements')->join('products', 'products.id', '=', 'stock_movements.product_id')
                ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')->orderByDesc('stock_movements.id')->limit(8)
                ->get(['stock_movements.id', 'stock_movements.type', 'stock_movements.quantity', 'stock_movements.created_at', 'products.name as product', 'warehouses.name as warehouse'])
                ->map(fn ($r) => (array) $r)->all();
            $out['movement_series'] = $this->movementSeries();
        }

        return $out;
    }

    /** Oxirgi 14 kunda kirim va chiqim harakatlari soni. */
    private function movementSeries(): array
    {
        $today = Carbon::today();
        $rows = DB::table('stock_movements')->whereDate('created_at', '>=', $today->copy()->subDays(self::DAYS - 1)->toDateString())
            ->selectRaw('DATE(created_at) d, SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) incoming, SUM(CASE WHEN quantity < 0 THEN 1 ELSE 0 END) outgoing')
            ->groupBy(DB::raw('DATE(created_at)'))->get();

        return $this->fillDays($rows, $today, ['incoming', 'outgoing']);
    }

    private function overviewPurchases(Carbon $today): array
    {
        $month = $today->copy()->startOfMonth()->toDateString();
        $base = fn () => DB::table('stock_documents')->where('type', 'purchase')->where('status', 'approved')->whereDate('doc_date', '>=', $month);

        return [
            'month_total' => $this->m($base()->sum('total')),
            'month_count' => $base()->count(),
            'drafts' => DB::table('stock_documents')->where('type', 'purchase')->where('status', 'draft')->count(),
            'by_supplier' => $base()->join('suppliers', 'suppliers.id', '=', 'stock_documents.supplier_id')
                ->selectRaw('suppliers.name, COALESCE(SUM(stock_documents.total),0) total')->groupBy('suppliers.id', 'suppliers.name')->orderByDesc('total')->limit(6)->get()
                ->map(fn ($r) => ['name' => $r->name, 'total' => (float) $r->total])->all(),
        ];
    }

    private function overviewCustomers(): array
    {
        $debtSales = DB::table('sales')->where('status', 'approved')->where('type', 'sale')->sum('debt_amount');
        $debtReturns = DB::table('sales')->where('status', 'approved')->where('type', 'return')->sum('debt_amount');
        $paid = DB::table('customer_payments')->where('status', 'approved')->sum('amount');

        return [
            'count' => DB::table('customers')->where('is_active', true)->count(),
            'debt' => $this->m((float) $debtSales - (float) $debtReturns - (float) $paid),
        ];
    }

    private function overviewFinance(Carbon $today, bool $cash, bool $bank, bool $expenses): ?array
    {
        if (! $cash && ! $bank && ! $expenses) {
            return null;
        }
        $out = [];
        if ($cash) {
            $r = DB::table('cash_transactions')->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END),0) b")->first();
            $out['cash_balance'] = $this->m($r->b);
        }
        if ($bank) {
            $rows = DB::table('bank_accounts')->leftJoin('bank_transactions as bt', function ($j) {
                $j->on('bt.status', '=', DB::raw("'approved'"))->where(function ($q) {
                    $q->on('bt.from_account_id', '=', 'bank_accounts.id')->orOn('bt.to_account_id', '=', 'bank_accounts.id');
                });
            })->selectRaw('bank_accounts.id, bank_accounts.opening_balance, COALESCE(SUM(CASE WHEN bt.to_account_id = bank_accounts.id THEN bt.amount ELSE -bt.amount END),0) movement')
                ->groupBy('bank_accounts.id', 'bank_accounts.opening_balance')->get();
            $sum = 0.0;
            foreach ($rows as $r) {
                $sum += (float) $r->opening_balance + (float) $r->movement;
            }
            $out['bank_balance'] = $this->m($sum);
        }
        if ($expenses) {
            $month = $today->copy()->startOfMonth()->toDateString();
            $out['month_expenses'] = $this->m(DB::table('expenses')->where('status', 'approved')->whereDate('expense_date', '>=', $month)->sum('amount'));
            $out['pending_expenses'] = DB::table('expenses')->where('status', 'pending')->count();
            $out['expenses_by_category'] = DB::table('expenses')->where('status', 'approved')->whereDate('expense_date', '>=', $month)
                ->selectRaw('category, COALESCE(SUM(amount),0) total')->groupBy('category')->orderByDesc('total')->limit(6)->get()
                ->map(fn ($r) => ['category' => $r->category, 'total' => (float) $r->total])->all();
        }

        return $out;
    }
}
