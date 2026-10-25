<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use Illuminate\Http\JsonResponse;

/** Xarid (kirim) va yetkazib beruvchiga qaytarish — ruxsatlar: purchases.* */
class PurchaseDocumentController extends AbstractDocumentController
{
    protected array $types = ['purchase', 'purchase_return'];

    /** Tasdiqlangan xaridning qaytarish mumkin bo'lgan qatorlari. */
    public function returnable(int $id): JsonResponse
    {
        $doc = StockDocument::where('type', 'purchase')->where('status', 'approved')->with('items.product.unit')->findOrFail($id);

        $returned = StockDocumentItem::selectRaw('product_id, SUM(quantity) as qty')
            ->whereHas('document', fn ($q) => $q->where('parent_id', $doc->id)->where('type', 'purchase_return')->where('status', 'approved'))
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $rows = $doc->items->map(function ($item) use ($returned) {
            $done = (float) ($returned[$item->product_id] ?? 0);

            return [
                'product_id' => $item->product_id,
                'name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'unit' => $item->product?->unit?->short_name,
                'allow_fraction' => (bool) $item->product?->unit?->allow_fraction,
                'purchased' => $item->quantity,
                'returned' => number_format($done, 3, '.', ''),
                'remaining' => number_format(max((float) $item->quantity - $done, 0), 3, '.', ''),
                'unit_cost' => $item->unit_cost,
            ];
        })->values();

        return response()->json(['document' => $doc->only(['id', 'number', 'warehouse_id', 'supplier_id']), 'items' => $rows]);
    }
}
