<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashboxShift;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kassa oynasi (POS) uchun yordamchi endpointlar. Ruxsat: sales.create. */
class PosController extends Controller
{
    /** Joriy foydalanuvchining ochiq smenasi, tanlanadigan omborlar, ruxsatlar va chek rekvizitlari. */
    public function context(Request $request): JsonResponse
    {
        $user = $request->user();
        $shift = CashboxShift::with('cashbox:id,code,name,branch_id')
            ->where('user_id', $user->id)->where('status', 'open')->first();

        $warehouses = [];
        $defaultWarehouse = null;
        if ($shift) {
            $branchId = $shift->cashbox->branch_id;
            $warehouses = Warehouse::where('is_active', true)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->orderBy('id')->get(['id', 'code', 'name']);
            $defaultWarehouse = $warehouses->count() === 1 ? $warehouses->first()->id : null;
        }

        return response()->json([
            'shift' => $shift ? ['id' => $shift->id, 'opened_at' => $shift->opened_at, 'cashbox' => $shift->cashbox] : null,
            'warehouses' => $warehouses,
            'default_warehouse_id' => $defaultWarehouse,
            'can' => [
                'discount' => $user->can('sales.discount'),
                'debt' => $user->can('sales.debt'),
                'customers_create' => $user->can('customers.create'),
            ],
            'methods' => SaleService::METHOD_LABELS,
            'reference_required' => SaleService::REFERENCE_REQUIRED,
            'receipt' => config('erp.receipt'),
        ]);
    }

    /** Mahsulot qidirish (nomi, SKU, shtrix-kod). Shtrix-kod/SKU aniq mos kelsa, birinchi bo'lib chiqadi. */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = mb_strtolower(trim((string) ($data['search'] ?? '')));
        $like = '%'.addcslashes($search, '%_\\').'%';

        $products = $this->productQuery((int) $data['warehouse_id'])
            ->when($search !== '', fn ($q) => $q
                ->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(sku) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(barcode) LIKE ?', [$like]))
                ->orderByRaw('CASE WHEN LOWER(barcode) = ? OR LOWER(sku) = ? THEN 0 ELSE 1 END', [$search, $search]))
            ->orderBy('name')
            ->limit(30)
            ->get();

        return response()->json($products->map(fn (Product $p) => $this->present($p))->values());
    }

    /**
     * Skaner uchun ANIQ qidiruv: faqat shtrix-kod yoki SKU to'liq mos kelgan faol mahsulot.
     * Qisman moslik (LIKE) ataylab yo'q: skanerlangan kod boshqa mahsulot nomi/kodining bo'lagiga
     * tasodifan mos kelib, noto'g'ri tovar savatga tushmasligi kerak.
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $code = mb_strtolower(trim($data['code']));
        $product = $this->productQuery((int) $data['warehouse_id'])
            ->where(fn ($w) => $w->whereRaw('LOWER(barcode) = ?', [$code])->orWhereRaw('LOWER(sku) = ?', [$code]))
            // Shtrix-kod SKU dan ustun: ikkalasi turli mahsulotlarda mos kelsa, shtrix-kod egasi olinadi.
            ->orderByRaw('CASE WHEN LOWER(barcode) = ? THEN 0 ELSE 1 END', [$code])
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Bu kod bo‘yicha mahsulot topilmadi.'], 404);
        }

        return response()->json($this->present($product));
    }

    private function productQuery(int $warehouseId)
    {
        return Product::query()
            ->select('products.*')
            ->addSelect(['stock_qty' => WarehouseStock::select('quantity')
                ->whereColumn('warehouse_stocks.product_id', 'products.id')
                ->where('warehouse_stocks.warehouse_id', $warehouseId)
                ->limit(1)])
            ->with('unit:id,short_name,allow_fraction')
            ->where('is_active', true);
    }

    private function present(Product $p): array
    {
        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'sale_price' => $p->sale_price,
            'wholesale_price' => $p->wholesale_price,
            'unit' => $p->unit?->short_name,
            'allow_fraction' => (bool) $p->unit?->allow_fraction,
            'stock' => $p->stock_qty === null ? '0.000' : number_format((float) $p->stock_qty, 3, '.', ''),
        ];
    }
}
