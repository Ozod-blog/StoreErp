<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\WarehouseRequest;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'branch_id']);
        }

        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));

        return Warehouse::with('branch:id,name')
            ->withCount(['stocks as positions_count' => fn ($q) => $q->where('quantity', '<>', 0)])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(address) LIKE ?', [$like]));
            })
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** Ombor formasidagi filial tanlovi (warehouses.view yetarli). */
    public function branchOptions(): JsonResponse
    {
        return response()->json(Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']));
    }

    public function store(WarehouseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $warehouse = Warehouse::create($data);
        AuditLogger::log('warehouses.create', $warehouse, null, $data);

        return response()->json($warehouse->load('branch:id,name'), 201);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $data = $request->validated();

        $deactivating = array_key_exists('is_active', $data) && ! filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        if ($deactivating && $warehouse->is_active && $this->hasStock($warehouse)) {
            return response()->json(['message' => 'Omborda qoldiq bor — avval qoldiqni ko‘chiring yoki chiqim qiling.'], 422);
        }

        $warehouse->fill($data);
        $old = Arr::only($warehouse->getOriginal(), array_keys($warehouse->getDirty()));
        $new = $warehouse->getDirty();
        $warehouse->save();
        AuditLogger::log('warehouses.update', $warehouse, $old, $new);

        return response()->json($warehouse->fresh()->load('branch:id,name'));
    }

    /** Ombor o'chirilmaydi — faol emas holatga o'tadi (qoldiq bo'lmasa). */
    public function destroy(Warehouse $warehouse): JsonResponse
    {
        if ($this->hasStock($warehouse)) {
            return response()->json(['message' => 'Omborda qoldiq bor — avval qoldiqni ko‘chiring yoki chiqim qiling.'], 422);
        }

        $warehouse->update(['is_active' => false]);
        AuditLogger::log('warehouses.deactivate', $warehouse, ['is_active' => true], ['is_active' => false]);

        return response()->json(['message' => 'Ombor faol emas holatga o‘tkazildi.']);
    }

    /** Ombordagi mahsulot qoldiqlari (faqat o'qish). */
    public function stocks(Request $request, Warehouse $warehouse)
    {
        $perPage = max(5, min((int) $request->query('per_page', 20), 100));
        $search = trim((string) $request->query('search', ''));

        return WarehouseStock::with(['product:id,sku,name,unit_id,deleted_at', 'product.unit:id,short_name,allow_fraction'])
            ->where('warehouse_id', $warehouse->id)
            ->where('quantity', '<>', 0)
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->whereHas('product', fn ($p) => $p->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(sku) LIKE ?', [$like])));
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function hasStock(Warehouse $warehouse): bool
    {
        return $warehouse->stocks()->where('quantity', '<>', 0)->exists();
    }
}
