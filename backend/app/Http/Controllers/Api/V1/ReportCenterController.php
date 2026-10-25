<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ReportCenter;
use App\Services\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportCenterController extends Controller
{
    public function __construct(private ReportCenter $center)
    {
    }

    /** Hisobotlar ro'yxati va filtr variantlari (filial, ombor, xodim). */
    public function index()
    {
        return response()->json([
            'reports' => $this->center->catalog(),
            'branches' => DB::table('branches')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'warehouses' => DB::table('warehouses')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'branch_id']),
            'users' => DB::table('users')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Filtr uchun mahsulot qidiruvi (products.view ruxsatiga bog'liq emas, faqat reports.view). */
    public function productOptions(Request $request)
    {
        $q = trim((string) $request->query('search', ''));
        $like = '%'.mb_strtolower(addcslashes($q, '%_\\')).'%';

        return DB::table('products')->whereNull('deleted_at')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(sku) LIKE ?', [$like])))
            ->orderBy('name')->limit(20)->get(['id', 'sku', 'name']);
    }

    public function show(Request $request, string $report)
    {
        $this->assertKnown($report);

        return response()->json($this->center->run($report, $this->filters($request)));
    }

    public function export(Request $request, string $report, ReportExporter $exporter)
    {
        $this->assertKnown($report);
        $format = $request->validate(['format' => ['required', 'in:xlsx,pdf']])['format'];
        $filters = $this->filters($request);
        $data = $this->center->run($report, $filters);

        AuditLogger::log('reports.export', null, null, ['report' => $report, 'format' => $format, 'filters' => $filters]);

        return $exporter->download($data, $format, $this->labels($filters));
    }

    private function assertKnown(string $report): void
    {
        abort_unless(array_key_exists($report, $this->center->definitions()), 404, 'Hisobot topilmadi.');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }

    /** Eksport faylida ko'rsatiladigan o'qiladigan filtr nomlari. */
    private function labels(array $f): array
    {
        $out = [];
        if (! empty($f['branch_id'])) {
            $out['Filial'] = DB::table('branches')->where('id', $f['branch_id'])->value('name');
        }
        if (! empty($f['warehouse_id'])) {
            $out['Ombor'] = DB::table('warehouses')->where('id', $f['warehouse_id'])->value('name');
        }
        if (! empty($f['product_id'])) {
            $out['Mahsulot'] = DB::table('products')->where('id', $f['product_id'])->value('name');
        }
        if (! empty($f['user_id'])) {
            $out['Xodim'] = DB::table('users')->where('id', $f['user_id'])->value('name');
        }

        return $out;
    }
}
