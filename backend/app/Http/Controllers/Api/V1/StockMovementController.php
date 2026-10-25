<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use Illuminate\Http\Request;

/** Qoldiq harakati tarixi (faqat o'qish). */
class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(5, min((int) $request->query('per_page', 25), 100));
        $search = trim((string) $request->query('search', ''));

        return StockMovement::with([
            'warehouse:id,name', 'product:id,sku,name,unit_id,deleted_at', 'product.unit:id,short_name',
            'document:id,number,type', 'sale:id,number,type', 'user:id,name',
        ])
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', (int) $request->query('warehouse_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->query('product_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', (string) $request->query('type')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('date_to')))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $q->whereHas('product', fn ($p) => $p->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(sku) LIKE ?', [$like])));
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
