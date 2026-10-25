<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Dec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $data['date_from'] = $data['date_from'] ?? now()->startOfMonth()->toDateString();
        $data['date_to'] = $data['date_to'] ?? now()->toDateString();
        return $data;
    }

    public function branchOptions()
    {
        return DB::table('branches')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function salesQuery(array $f)
    {
        return DB::table('sales')->where('status', 'approved')
            ->whereDate('sold_at', '>=', $f['date_from'])
            ->whereDate('sold_at', '<=', $f['date_to'])
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('branch_id', $f['branch_id']));
    }

    private function money($value): string
    {
        return Dec::str(Dec::units((string) ($value ?? '0'), 2), 2);
    }

    public function dashboard(Request $request)
    {
        $f = $this->filters($request);
        $sales = $this->salesQuery($f);
        $sold = (clone $sales)->where('type', 'sale')->selectRaw('COALESCE(SUM(total),0) total, COALESCE(SUM(cogs),0) cogs, COUNT(*) count')->first();
        $returns = (clone $sales)->where('type', 'return')->selectRaw('COALESCE(SUM(total),0) total, COALESCE(SUM(cogs),0) cogs, COUNT(*) count')->first();
        $expenses = DB::table('expenses')->where('status', 'approved')->whereDate('expense_date', '>=', $f['date_from'])->whereDate('expense_date', '<=', $f['date_to'])
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('branch_id', $f['branch_id']))->sum('amount');

        $netSalesUnits = Dec::units($sold->total, 2) - Dec::units($returns->total, 2);
        $netCogsUnits = Dec::units($sold->cogs, 2) - Dec::units($returns->cogs, 2);
        $grossProfitUnits = $netSalesUnits - $netCogsUnits;
        $expenseUnits = Dec::units((string) $expenses, 2);

        $cashRows = DB::table('cash_transactions')->join('cashboxes', 'cashboxes.id', '=', 'cash_transactions.cashbox_id')
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('cashboxes.branch_id', $f['branch_id']))
            ->selectRaw("COALESCE(SUM(CASE WHEN cash_transactions.direction = 'in' THEN cash_transactions.amount ELSE -cash_transactions.amount END),0) balance")->first();
        $cashBalance = Dec::units((string) ($cashRows->balance ?? '0'), 2);

        $bankRows = DB::table('bank_accounts')->leftJoin('bank_transactions as bt', function ($join) {
            $join->on('bt.status', '=', DB::raw("'approved'"))
                ->where(function ($q) {
                    $q->on('bt.from_account_id', '=', 'bank_accounts.id')->orOn('bt.to_account_id', '=', 'bank_accounts.id');
                });
        })->when(!empty($f['branch_id']), fn ($q) => $q->where('bank_accounts.branch_id', $f['branch_id']))
            ->selectRaw('bank_accounts.id, bank_accounts.opening_balance, COALESCE(SUM(CASE WHEN bt.to_account_id = bank_accounts.id THEN bt.amount ELSE -bt.amount END),0) movement')
            ->groupBy('bank_accounts.id', 'bank_accounts.opening_balance')->get();
        $bankBalance = 0;
        foreach ($bankRows as $row) $bankBalance += Dec::units((string) $row->opening_balance, 2) + Dec::units((string) $row->movement, 2);

        $productCount = DB::table('products')->whereNull('deleted_at')->where('is_active', true)->count();
        $lowStockQuery = DB::table('products')->leftJoin('warehouse_stocks', 'warehouse_stocks.product_id', '=', 'products.id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'warehouse_stocks.warehouse_id')->whereNull('products.deleted_at')->where('products.is_active', true)
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('warehouses.branch_id', $f['branch_id']))
            ->selectRaw('products.id, products.name, products.sku, products.min_stock, COALESCE(SUM(warehouse_stocks.quantity),0) quantity')
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.min_stock')
            ->havingRaw('COALESCE(SUM(warehouse_stocks.quantity),0) <= products.min_stock');
        $lowStockCount = DB::query()->fromSub(clone $lowStockQuery, 'low_stock_report')->count();

        $debtSales = DB::table('sales')->where('status', 'approved')->where('type', 'sale')->sum('debt_amount');
        $debtReturns = DB::table('sales')->where('status', 'approved')->where('type', 'return')->sum('debt_amount');
        $customerPayments = DB::table('customer_payments')->where('status', 'approved')->sum('amount');
        $customerDebt = Dec::units((string) $debtSales, 2) - Dec::units((string) $debtReturns, 2) - Dec::units((string) $customerPayments, 2);

        return response()->json([
            'period' => ['date_from' => $f['date_from'], 'date_to' => $f['date_to'], 'branch_id' => $f['branch_id'] ?? null],
            'kpis' => [
                'sales_gross' => $this->money($sold->total), 'returns' => $this->money($returns->total),
                'sales_net' => Dec::str($netSalesUnits, 2), 'gross_profit' => Dec::str($grossProfitUnits, 2),
                'approved_expenses' => Dec::str($expenseUnits, 2), 'operating_result' => Dec::str($grossProfitUnits - $expenseUnits, 2),
                'cash_balance' => Dec::str($cashBalance, 2), 'bank_balance' => Dec::str($bankBalance, 2),
                'customer_debt_estimate' => Dec::str($customerDebt, 2), 'active_products' => $productCount,
                'low_stock_products' => $lowStockCount, 'sales_count' => (int) $sold->count,
            ],
        ]);
    }

    public function sales(Request $request)
    {
        $f = $this->filters($request);
        $base = $this->salesQuery($f);
        $daily = (clone $base)->selectRaw('DATE(sold_at) AS sale_day, type, SUM(total) AS total, COUNT(*) AS documents')
            ->groupBy(DB::raw('DATE(sold_at)'), 'type')->orderBy('sale_day')->get();
        $topProducts = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'approved')->where('sales.type', 'sale')->whereDate('sales.sold_at', '>=', $f['date_from'])->whereDate('sales.sold_at', '<=', $f['date_to'])
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('sales.branch_id', $f['branch_id']))
            ->selectRaw('products.id, products.sku, products.name, SUM(sale_items.quantity) quantity, SUM(sale_items.net_total) total')
            ->groupBy('products.id', 'products.sku', 'products.name')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'quantity' => (string) $r->quantity, 'total' => $this->money($r->total)]);
        $sold = (clone $base)->where('type', 'sale')->selectRaw('COALESCE(SUM(total),0) total, COALESCE(SUM(discount),0) discount, COUNT(*) count')->first();
        $returns = (clone $base)->where('type', 'return')->selectRaw('COALESCE(SUM(total),0) total, COUNT(*) count')->first();
        return response()->json([
            'period' => $f,
            'summary' => ['sales' => $this->money($sold->total), 'returns' => $this->money($returns->total), 'net_sales' => Dec::str(Dec::units($sold->total, 2) - Dec::units($returns->total, 2), 2), 'discounts' => $this->money($sold->discount), 'sales_count' => (int) $sold->count, 'return_count' => (int) $returns->count],
            'daily' => $daily->map(fn ($r) => ['date' => $r->sale_day, 'type' => $r->type, 'total' => $this->money($r->total), 'documents' => (int) $r->documents]),
            'top_products' => $topProducts,
        ]);
    }

    public function inventory(Request $request)
    {
        $f = $this->filters($request);
        $base = DB::table('products')->leftJoin('warehouse_stocks', 'warehouse_stocks.product_id', '=', 'products.id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'warehouse_stocks.warehouse_id')->whereNull('products.deleted_at')->where('products.is_active', true)
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('warehouses.branch_id', $f['branch_id']))
            ->selectRaw('products.id, products.sku, products.name, products.min_stock, products.sale_price, COALESCE(SUM(warehouse_stocks.quantity),0) quantity')
            ->groupBy('products.id', 'products.sku', 'products.name', 'products.min_stock', 'products.sale_price');
        $totals = DB::query()->fromSub(clone $base, 'stock_report')
            ->selectRaw('COUNT(*) products, COALESCE(SUM(CASE WHEN quantity <= min_stock THEN 1 ELSE 0 END),0) low_stock, ROUND(COALESCE(SUM(quantity * sale_price),0),2) retail_value_estimate')->first();
        $rows = (clone $base)->orderBy('products.name')->limit(500)->get()->map(function ($r) {
            $qtyUnits = Dec::units((string) $r->quantity, 3);
            $priceUnits = Dec::units((string) $r->sale_price, 2);
            $stockValueUnits = Dec::mulDiv(max(0, $qtyUnits), max(0, $priceUnits), 1000);
            return ['id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'quantity' => (string) $r->quantity, 'min_stock' => (string) $r->min_stock, 'sale_price' => $this->money($r->sale_price), 'retail_value_estimate' => Dec::str($stockValueUnits, 2), 'low_stock' => Dec::units((string) $r->quantity, 3) <= Dec::units((string) $r->min_stock, 3)];
        });
        return response()->json(['period' => $f, 'summary' => ['products' => (int) $totals->products, 'low_stock' => (int) $totals->low_stock, 'retail_value_estimate' => $this->money($totals->retail_value_estimate)], 'data' => $rows]);
    }

    public function finance(Request $request)
    {
        $f = $this->filters($request);
        $expenses = DB::table('expenses')->where('status', 'approved')->whereDate('expense_date', '>=', $f['date_from'])->whereDate('expense_date', '<=', $f['date_to'])
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('branch_id', $f['branch_id']))
            ->selectRaw('category, SUM(amount) total, COUNT(*) count')->groupBy('category')->orderByDesc('total')->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => $this->money($r->total), 'count' => (int) $r->count]);
        $cash = DB::table('cash_transactions')->join('cashboxes', 'cashboxes.id', '=', 'cash_transactions.cashbox_id')
            ->whereDate('cash_transactions.occurred_at', '>=', $f['date_from'])->whereDate('cash_transactions.occurred_at', '<=', $f['date_to'])
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('cashboxes.branch_id', $f['branch_id']))
            ->selectRaw("COALESCE(SUM(CASE WHEN cash_transactions.direction = 'in' THEN cash_transactions.amount ELSE 0 END),0) incoming, COALESCE(SUM(CASE WHEN cash_transactions.direction = 'out' THEN cash_transactions.amount ELSE 0 END),0) outgoing")->first();
        $bank = DB::table('bank_transactions')->join('bank_accounts', function ($join) {
            $join->on('bank_accounts.id', '=', 'bank_transactions.from_account_id')->orOn('bank_accounts.id', '=', 'bank_transactions.to_account_id');
        })->where('bank_transactions.status', 'approved')->whereDate('bank_transactions.occurred_at', '>=', $f['date_from'])->whereDate('bank_transactions.occurred_at', '<=', $f['date_to'])
            ->when(!empty($f['branch_id']), fn ($q) => $q->where('bank_accounts.branch_id', $f['branch_id']))
            ->selectRaw("COALESCE(SUM(CASE WHEN bank_transactions.to_account_id = bank_accounts.id THEN bank_transactions.amount ELSE 0 END),0) incoming, COALESCE(SUM(CASE WHEN bank_transactions.from_account_id = bank_accounts.id THEN bank_transactions.amount ELSE 0 END),0) outgoing")->first();
        $expenseTotal = DB::table('expenses')->where('status', 'approved')->whereDate('expense_date', '>=', $f['date_from'])->whereDate('expense_date', '<=', $f['date_to'])->when(!empty($f['branch_id']), fn ($q) => $q->where('branch_id', $f['branch_id']))->sum('amount');
        return response()->json(['period' => $f, 'cash_movements' => ['incoming' => $this->money($cash->incoming), 'outgoing' => $this->money($cash->outgoing)], 'bank_movements' => ['incoming' => $this->money($bank->incoming), 'outgoing' => $this->money($bank->outgoing)], 'approved_expenses' => $this->money($expenseTotal), 'expenses_by_category' => $expenses]);
    }
}
