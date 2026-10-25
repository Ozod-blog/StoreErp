<?php

namespace App\Services;

use App\Support\Dec;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hisobotlar markazi: bir xil shakldagi (ustunlar + qatorlar + jami) jadval hisobotlari.
 *
 * TANNARX QOIDASI (butun loyihada bitta): har bir sotuv qatorining tannarxi = miqdor × `sale_items.unit_cost`,
 * `unit_cost` esa sotuv paytidagi vaznli o'rtacha tannarx (products.avg_cost) va keyin o'zgarmaydi.
 * Qaytarishlar (type=return) tushum, miqdor va tannarxdan ayriladi (asl sotuvning tannarxi bilan).
 * Foyda = sof tushum − tannarx. Marja % = foyda / sof tushum × 100. Bekor qilingan hujjatlar hisobga olinmaydi.
 */
class ReportCenter
{
    public const FILTERS = ['date_from', 'date_to', 'branch_id', 'warehouse_id', 'product_id', 'user_id'];

    /** key => [title, description, filters, columns[key, label, type]] ; type: text|money|qty|int|percent */
    public function definitions(): array
    {
        return [
            'sales_daily' => [
                'title' => 'Kunlik savdo',
                'description' => 'Har kun bo‘yicha sotuv, qaytarish va sof savdo.',
                'filters' => ['date_from', 'date_to', 'branch_id', 'warehouse_id', 'user_id'],
                'columns' => [
                    ['date', 'Sana', 'text'], ['sales_count', 'Sotuvlar soni', 'int'], ['sales_total', 'Sotuv summasi', 'money'],
                    ['returns_total', 'Qaytarishlar', 'money'], ['net_sales', 'Sof savdo', 'money'],
                ],
            ],
            'sales_by_product' => [
                'title' => 'Mahsulotlar bo‘yicha sotuv',
                'description' => 'Sof sotilgan miqdor va tushum (qaytarishlar ayrilgan).',
                'filters' => ['date_from', 'date_to', 'branch_id', 'warehouse_id', 'product_id', 'user_id'],
                'columns' => [['sku', 'Kod', 'text'], ['name', 'Mahsulot', 'text'], ['quantity', 'Miqdor', 'qty'], ['revenue', 'Tushum', 'money']],
            ],
            'profit_by_product' => [
                'title' => 'Foyda va marja (mahsulot bo‘yicha)',
                'description' => 'Tushum, tannarx (vaznli o‘rtacha, savdo paytida qotirilgan), foyda va marja.',
                'filters' => ['date_from', 'date_to', 'branch_id', 'warehouse_id', 'product_id', 'user_id'],
                'columns' => [
                    ['sku', 'Kod', 'text'], ['name', 'Mahsulot', 'text'], ['quantity', 'Miqdor', 'qty'], ['revenue', 'Tushum', 'money'],
                    ['cost', 'Tannarx', 'money'], ['profit', 'Foyda', 'money'], ['margin', 'Marja %', 'percent'],
                ],
            ],
            'sales_by_employee' => [
                'title' => 'Xodimlar bo‘yicha sotuv',
                'description' => 'Sotuvni rasmiylashtirgan xodim bo‘yicha tushum va foyda.',
                'filters' => ['date_from', 'date_to', 'branch_id', 'warehouse_id', 'user_id'],
                'columns' => [
                    ['employee', 'Xodim', 'text'], ['documents', 'Hujjatlar', 'int'], ['revenue', 'Sof tushum', 'money'],
                    ['cost', 'Tannarx', 'money'], ['profit', 'Foyda', 'money'], ['margin', 'Marja %', 'percent'],
                ],
            ],
            'stock' => [
                'title' => 'Ombor qoldiqlari',
                'description' => 'Hozirgi qoldiq, tannarx bo‘yicha va sotuv narxi bo‘yicha qiymat (sana filtri qo‘llanmaydi).',
                'filters' => ['branch_id', 'warehouse_id', 'product_id'],
                'columns' => [
                    ['warehouse', 'Ombor', 'text'], ['sku', 'Kod', 'text'], ['name', 'Mahsulot', 'text'], ['quantity', 'Qoldiq', 'qty'],
                    ['avg_cost', 'O‘rtacha tannarx', 'money'], ['cost_value', 'Tannarx qiymati', 'money'], ['retail_value', 'Sotuv qiymati', 'money'],
                ],
            ],
        ];
    }

    public function catalog(): array
    {
        $out = [];
        foreach ($this->definitions() as $key => $d) {
            $out[] = ['key' => $key, 'title' => $d['title'], 'description' => $d['description'], 'filters' => $d['filters']];
        }

        return $out;
    }

    public function run(string $key, array $f): array
    {
        $def = $this->definitions()[$key] ?? throw ValidationException::withMessages(['report' => 'Noma’lum hisobot.']);
        $f['date_from'] = $f['date_from'] ?? now()->startOfMonth()->toDateString();
        $f['date_to'] = $f['date_to'] ?? now()->toDateString();
        // Hisobot qo'llamaydigan filtrlar e'tiborga olinmaydi.
        $f = array_intersect_key($f, array_flip($def['filters']));

        $rows = match ($key) {
            'sales_daily' => $this->salesDaily($f),
            'sales_by_product' => $this->salesByProduct($f),
            'profit_by_product' => $this->profitByProduct($f),
            'sales_by_employee' => $this->salesByEmployee($f),
            'stock' => $this->stock($f),
        };

        $columns = array_map(fn ($c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2]], $def['columns']);

        return [
            'key' => $key,
            'title' => $def['title'],
            'period' => in_array('date_from', $def['filters'], true) ? ['date_from' => $f['date_from'], 'date_to' => $f['date_to']] : null,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $this->totals($columns, $rows),
        ];
    }

    private function money(mixed $v): string
    {
        return Dec::str(Dec::units((string) ($v ?? '0'), 2), 2);
    }

    private function qty(mixed $v): string
    {
        return Dec::str(Dec::units((string) ($v ?? '0'), 3), 3);
    }

    private function margin(string $profit, string $revenue): string
    {
        $rev = Dec::units($revenue, 2);
        if ($rev <= 0) {
            return '0.00';
        }

        return number_format(Dec::units($profit, 2) / $rev * 100, 2, '.', '');
    }

    /** Sotuv hujjatlari (sales) bo'yicha umumiy filtrlar. */
    private function salesBase(array $f, string $alias = 's'): Builder
    {
        return DB::table("sales as $alias")->where("$alias.status", 'approved')
            ->whereDate("$alias.sold_at", '>=', $f['date_from'])->whereDate("$alias.sold_at", '<=', $f['date_to'])
            ->when(! empty($f['branch_id']), fn ($q) => $q->where("$alias.branch_id", $f['branch_id']))
            ->when(! empty($f['warehouse_id']), fn ($q) => $q->where("$alias.warehouse_id", $f['warehouse_id']))
            ->when(! empty($f['user_id']), fn ($q) => $q->where("$alias.created_by", $f['user_id']));
    }

    /** Sotuv qatorlari (sale_items) + hujjat + mahsulot. */
    private function itemsBase(array $f): Builder
    {
        return $this->salesBase($f)->join('sale_items as si', 'si.sale_id', '=', 's.id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->when(! empty($f['product_id']), fn ($q) => $q->where('si.product_id', $f['product_id']));
    }

    private function salesDaily(array $f): array
    {
        return $this->salesBase($f)
            ->selectRaw("DATE(s.sold_at) AS day,
                SUM(CASE WHEN s.type = 'sale' THEN 1 ELSE 0 END) AS sales_count,
                ROUND(SUM(CASE WHEN s.type = 'sale' THEN s.total ELSE 0 END), 2) AS sales_total,
                ROUND(SUM(CASE WHEN s.type = 'return' THEN s.total ELSE 0 END), 2) AS returns_total")
            ->groupBy(DB::raw('DATE(s.sold_at)'))->orderBy('day')->get()
            ->map(function ($r) {
                $sales = $this->money($r->sales_total);
                $ret = $this->money($r->returns_total);

                return [
                    'date' => (string) $r->day, 'sales_count' => (int) $r->sales_count, 'sales_total' => $sales,
                    'returns_total' => $ret, 'net_sales' => Dec::str(Dec::units($sales, 2) - Dec::units($ret, 2), 2),
                ];
            })->all();
    }

    private const SIGN_QTY = "CASE WHEN s.type = 'sale' THEN si.quantity ELSE -si.quantity END";

    private const SIGN_REV = "CASE WHEN s.type = 'sale' THEN si.net_total ELSE -si.net_total END";

    private const SIGN_COST = "CASE WHEN s.type = 'sale' THEN si.quantity * si.unit_cost ELSE -si.quantity * si.unit_cost END";

    private function productAgg(array $f): \Illuminate\Support\Collection
    {
        return $this->itemsBase($f)
            ->selectRaw('p.id, p.sku, p.name, SUM('.self::SIGN_QTY.') AS qty, ROUND(SUM('.self::SIGN_REV.'), 2) AS revenue, ROUND(SUM('.self::SIGN_COST.'), 2) AS cost')
            ->groupBy('p.id', 'p.sku', 'p.name')->orderByDesc('revenue')->limit(5000)->get();
    }

    private function salesByProduct(array $f): array
    {
        return $this->productAgg($f)->map(fn ($r) => [
            'sku' => $r->sku, 'name' => $r->name, 'quantity' => $this->qty($r->qty), 'revenue' => $this->money($r->revenue),
        ])->all();
    }

    private function profitByProduct(array $f): array
    {
        return $this->productAgg($f)->map(function ($r) {
            $rev = $this->money($r->revenue);
            $cost = $this->money($r->cost);
            $profit = Dec::str(Dec::units($rev, 2) - Dec::units($cost, 2), 2);

            return [
                'sku' => $r->sku, 'name' => $r->name, 'quantity' => $this->qty($r->qty), 'revenue' => $rev, 'cost' => $cost,
                'profit' => $profit, 'margin' => $this->margin($profit, $rev),
            ];
        })->all();
    }

    private function salesByEmployee(array $f): array
    {
        return $this->salesBase($f)->join('users as u', 'u.id', '=', 's.created_by')
            ->selectRaw("u.id, u.name,
                COUNT(*) AS documents,
                ROUND(SUM(CASE WHEN s.type = 'sale' THEN s.total ELSE -s.total END), 2) AS revenue,
                ROUND(SUM(CASE WHEN s.type = 'sale' THEN s.cogs ELSE -s.cogs END), 2) AS cost")
            ->groupBy('u.id', 'u.name')->orderByDesc('revenue')->get()
            ->map(function ($r) {
                $rev = $this->money($r->revenue);
                $cost = $this->money($r->cost);
                $profit = Dec::str(Dec::units($rev, 2) - Dec::units($cost, 2), 2);

                return [
                    'employee' => $r->name, 'documents' => (int) $r->documents, 'revenue' => $rev, 'cost' => $cost,
                    'profit' => $profit, 'margin' => $this->margin($profit, $rev),
                ];
            })->all();
    }

    private function stock(array $f): array
    {
        return DB::table('warehouse_stocks as ws')
            ->join('products as p', 'p.id', '=', 'ws.product_id')
            ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->whereNull('p.deleted_at')
            ->when(! empty($f['branch_id']), fn ($q) => $q->where('w.branch_id', $f['branch_id']))
            ->when(! empty($f['warehouse_id']), fn ($q) => $q->where('ws.warehouse_id', $f['warehouse_id']))
            ->when(! empty($f['product_id']), fn ($q) => $q->where('ws.product_id', $f['product_id']))
            ->selectRaw('w.name AS warehouse, p.sku, p.name, ws.quantity, ROUND(p.avg_cost, 2) AS avg_cost,
                ROUND(ws.quantity * p.avg_cost, 2) AS cost_value, ROUND(ws.quantity * p.sale_price, 2) AS retail_value')
            ->orderBy('w.name')->orderBy('p.name')->limit(5000)->get()
            ->map(fn ($r) => [
                'warehouse' => $r->warehouse, 'sku' => $r->sku, 'name' => $r->name, 'quantity' => $this->qty($r->quantity),
                'avg_cost' => $this->money($r->avg_cost), 'cost_value' => $this->money($r->cost_value), 'retail_value' => $this->money($r->retail_value),
            ])->all();
    }

    /** Jami qator: pul/miqdor/son ustunlari yig'indisi; marja jami foyda va tushumdan qayta hisoblanadi. */
    private function totals(array $columns, array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        $t = [];
        foreach ($columns as $c) {
            $k = $c['key'];
            if ($c['type'] === 'money') {
                $t[$k] = Dec::str(array_sum(array_map(fn ($r) => Dec::units($r[$k], 2), $rows)), 2);
            } elseif ($c['type'] === 'qty') {
                // Ombor qoldig'ida turli birliklar (kg, dona) aralashadi: miqdor jami ma'noga ega emas.
                $t[$k] = null;
            } elseif ($c['type'] === 'int') {
                $t[$k] = array_sum(array_column($rows, $k));
            } else {
                $t[$k] = null;
            }
        }
        if (isset($t['profit'], $t['revenue'])) {
            $t['margin'] = $this->margin($t['profit'], $t['revenue']);
        }

        return $t;
    }
}
