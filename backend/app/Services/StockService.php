<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Dec;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ombor hujjatlarining YAGONA kirish nuqtasi. Qoldiq va tannarx faqat shu yerdan o'zgaradi.
 *
 * Tannarx usuli: vaznli o'rtacha (products.avg_cost), butun mahsulot bo'yicha (barcha omborlar jami).
 *  - Xarid tasdiqlanganda: yangi = (jami qoldiq x eski + miqdor x sof narx) / (jami qoldiq + miqdor).
 *    Sof narx = qator narxi x (jami - chegirma) / jami (hujjat chegirmasi qatorlarga proporsional taqsimlanadi).
 *  - Chiqim, ko'chirish, inventarizatsiya, qaytarish avg_cost ni O'ZGARTIRMAYDI; harakatga joriy avg_cost yoziladi.
 *  - Xarid bekor qilinganda avg_cost qayta hisoblanmaydi (ma'lum cheklov, docs/PHASE4.md).
 */
class StockService
{
    /** Batch allocation preserved between transfer-out and transfer-in inside one document transaction. */
    private array $transferBatchAllocations = [];
    public function saveDraft(array $data, User $user, ?StockDocument $doc = null): StockDocument
    {
        return DB::transaction(function () use ($data, $user, $doc) {
            $type = $doc?->type ?? $data['type'];
            $parent = null;
            $warehouseId = (int) ($data['warehouse_id'] ?? 0);

            if ($type === 'purchase_return') {
                $parent = StockDocument::with('items')->lockForUpdate()->find($data['parent_id'] ?? 0);
                if (! $parent || $parent->type !== 'purchase' || $parent->status !== 'approved') {
                    throw new BusinessRuleException('Qaytarish faqat tasdiqlangan xarid hujjatiga nisbatan yaratiladi.');
                }
                $warehouseId = (int) $parent->warehouse_id;
            }

            $this->assertWarehouseActive($warehouseId);
            if ($type === 'transfer') {
                $this->assertWarehouseActive((int) $data['to_warehouse_id']);
            }
            if ($type === 'purchase') {
                $supplier = Supplier::find($data['supplier_id'] ?? 0);
                if (! $supplier || ! $supplier->is_active) {
                    throw new BusinessRuleException('Yetkazib beruvchi topilmadi yoki faol emas.');
                }
            }

            $products = Product::with('unit')
                ->whereIn('id', collect($data['items'])->pluck('product_id'))
                ->get()->keyBy('id');

            $rows = [];
            $subtotal = 0;
            foreach ($data['items'] as $row) {
                $product = $products[$row['product_id']] ?? null;
                if (! $product) {
                    throw new BusinessRuleException('Mahsulot topilmadi yoki arxivda.');
                }
                $qty = Dec::units($row['quantity'], 3);
                if ($qty < 0 || ($qty === 0 && $type !== 'inventory')) {
                    throw new BusinessRuleException("«{$product->name}»: miqdor 0 dan katta bo‘lishi kerak.");
                }
                if (! $product->unit->allow_fraction && $qty % 1000 !== 0) {
                    throw new BusinessRuleException("«{$product->name}» butun miqdorda hisoblanadi (kasr mumkin emas).");
                }

                $price = null;
                if (in_array($type, ['purchase', 'opening'], true)) {
                    $p2 = Dec::units($row['price'] ?? 0, 2);
                    $subtotal += Dec::lineTotal($qty, $p2);
                    $price = Dec::str($p2, 2);
                } elseif ($type === 'purchase_return') {
                    $parentItem = $parent->items->firstWhere('product_id', $product->id);
                    if (! $parentItem) {
                        throw new BusinessRuleException("«{$product->name}» asl xarid hujjatida yo‘q.");
                    }
                    $cost = (float) $parentItem->unit_cost;
                    $subtotal += (int) round($qty / 1000 * $cost * 100);
                    $price = Dec::str(Dec::units(round($cost, 2), 2), 2);
                }

                $receivedAt = $row['received_at'] ?? $data['doc_date'];
            $expiresAt = $row['expires_at'] ?? null;
            if ($expiresAt && $receivedAt && \Illuminate\Support\Carbon::parse($expiresAt)->lt(\Illuminate\Support\Carbon::parse($receivedAt))) throw new BusinessRuleException("«{$product->name}»: yaroqlilik muddati qabul sanasidan oldin bo‘lishi mumkin emas.");
            $rows[] = ['product_id' => $product->id, 'quantity' => Dec::str($qty, 3), 'price' => $price, 'batch_number' => isset($row['batch_number']) ? trim((string) $row['batch_number']) : null, 'received_at' => $receivedAt, 'expires_at' => $expiresAt];
            }

            $discount = 0;
            $paid = 0;
            $method = null;
            if ($type === 'purchase') {
                $discount = Dec::units($data['discount'] ?? 0, 2);
                if ($discount > $subtotal) {
                    throw new BusinessRuleException('Chegirma jami summadan oshib ketdi.');
                }
                $total = $subtotal - $discount;
                $paid = Dec::units($data['paid_amount'] ?? 0, 2);
                if ($paid > $total) {
                    throw new BusinessRuleException('To‘langan summa jami summadan oshib ketdi.');
                }
                if ($paid > 0) {
                    $method = $data['payment_method'] ?? null;
                    if (! in_array($method, ['cash', 'bank'], true)) {
                        throw new BusinessRuleException('To‘lov usulini tanlang (naqd yoki bank).');
                    }
                }
            } else {
                $total = in_array($type, ['purchase_return', 'opening'], true) ? $subtotal : 0;
            }

            $attrs = [
                'warehouse_id' => $warehouseId,
                'to_warehouse_id' => $type === 'transfer' ? (int) $data['to_warehouse_id'] : null,
                'supplier_id' => $type === 'purchase' ? (int) $data['supplier_id'] : $parent?->supplier_id,
                'parent_id' => $parent?->id,
                'doc_date' => $data['doc_date'],
                'discount' => Dec::str($discount, 2),
                'total' => Dec::str($total, 2),
                'paid_amount' => Dec::str($paid, 2),
                'payment_method' => $method,
                'reason' => $type === 'issue' ? ($data['reason'] ?? 'other') : null,
                'note' => $data['note'] ?? null,
            ];

            if ($doc) {
                $old = $doc->only(['warehouse_id', 'doc_date', 'total', 'note']);
                $doc->update($attrs);
                $doc->items()->delete();
                $action = 'stock_documents.update';
            } else {
                $doc = StockDocument::create($attrs + [
                    'type' => $type,
                    'status' => 'draft',
                    'number' => 'T'.Str::random(20), // vaqtincha; quyida id asosida almashtiriladi
                    'client_token' => $data['client_token'] ?? null,
                    'created_by' => $user->id,
                ]);
                $doc->update(['number' => StockDocument::PREFIXES[$type].'-'.str_pad((string) $doc->id, 6, '0', STR_PAD_LEFT)]);
                $old = null;
                $action = 'stock_documents.create';
            }

            $doc->items()->createMany($rows);
            AuditLogger::log($action, $doc, $old, ['number' => $doc->number, 'type' => $type, 'total' => $doc->total, 'items' => count($rows)], 'success', $user->id);

            return $this->fresh($doc);
        });
    }

    public function approve(int $documentId, User $user): StockDocument
    {
        return DB::transaction(function () use ($documentId, $user) {
            // Hujjat qatori qulflanadi: bir vaqtda ikki marta tasdiqlash (qayta yuborish) ikkinchi marta qoldiq o'zgartirmaydi.
            $doc = StockDocument::whereKey($documentId)->lockForUpdate()->firstOrFail();
            if ($doc->status !== 'draft') {
                throw new BusinessRuleException('Hujjat allaqachon tasdiqlangan yoki bekor qilingan.');
            }
            $doc->load('items');

            $this->assertWarehouseActive((int) $doc->warehouse_id);
            if ($doc->to_warehouse_id) {
                $this->assertWarehouseActive((int) $doc->to_warehouse_id);
            }

            $products = $this->lockProducts($doc->items->pluck('product_id')->all());
            foreach ($doc->items as $item) {
                if ($products[$item->product_id]->trashed()) {
                    throw new BusinessRuleException("«{$products[$item->product_id]->name}» arxivlangan — hujjatni tasdiqlab bo‘lmaydi.");
                }
            }

            match ($doc->type) {
                'purchase' => $this->applyPurchase($doc, $products, $user),
                'opening' => $this->applyPurchase($doc, $products, $user, 'opening'),
                'purchase_return' => $this->applyPurchaseReturn($doc, $products, $user),
                'issue' => $this->applyIssue($doc, $products, $user),
                'transfer' => $this->applyTransfer($doc, $products, $user),
                'inventory' => $this->applyInventory($doc, $products, $user),
            };

            $doc->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save();
            AuditLogger::log('stock_documents.approve', $doc, ['status' => 'draft'], [
                'status' => 'approved', 'number' => $doc->number, 'type' => $doc->type, 'total' => $doc->total,
            ], 'success', $user->id);

            return $this->fresh($doc);
        });
    }

    public function cancel(int $documentId, User $user, ?string $reason): StockDocument
    {
        return DB::transaction(function () use ($documentId, $user, $reason) {
            $doc = StockDocument::whereKey($documentId)->lockForUpdate()->firstOrFail();
            if ($doc->status === 'cancelled') {
                throw new BusinessRuleException('Hujjat allaqachon bekor qilingan.');
            }

            $wasApproved = $doc->status === 'approved';
            $reason = trim((string) $reason);
            if ($wasApproved && mb_strlen($reason) < 3) {
                throw new BusinessRuleException('Tasdiqlangan hujjatni bekor qilish sababini kiriting.');
            }

            if ($wasApproved) {
                if ($doc->type === 'purchase'
                    && StockDocument::where('parent_id', $doc->id)->where('type', 'purchase_return')->where('status', 'approved')->exists()) {
                    throw new BusinessRuleException('Bu xarid bo‘yicha tasdiqlangan qaytarish bor — avval qaytarishni bekor qiling.');
                }

                $movements = StockMovement::where('stock_document_id', $doc->id)->where('type', '!=', 'reversal')->orderByDesc('id')->get();
                $products = $this->lockProducts($movements->pluck('product_id')->unique()->all());
                foreach ($movements as $m) {
                    // Tarix o'chirilmaydi: teskari harakat yoziladi.
                    $this->move($doc, (int) $m->warehouse_id, $products[$m->product_id], -Dec::units($m->quantity, 3), 'reversal', $m->unit_cost === null ? null : (float) $m->unit_cost, $user->id);
                }
                $this->reverseDocumentBatches($doc);
            }

            $doc->forceFill([
                'status' => 'cancelled',
                'cancel_reason' => $reason !== '' ? $reason : null,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ])->save();
            AuditLogger::log('stock_documents.cancel', $doc, ['status' => $wasApproved ? 'approved' : 'draft'], [
                'status' => 'cancelled', 'number' => $doc->number, 'reason' => $reason,
            ], 'success', $user->id);

            return $this->fresh($doc);
        });
    }

    // ---------------------------------------------------------------- hujjat turlari

    private function applyPurchase(StockDocument $doc, Collection $products, User $user, string $moveType = 'purchase'): void
    {
        $subtotal = 0;
        foreach ($doc->items as $item) {
            $subtotal += Dec::lineTotal(Dec::units($item->quantity, 3), Dec::units($item->price, 2));
        }
        $total = $subtotal - Dec::units($doc->discount, 2);
        $ratio = $subtotal > 0 ? $total / $subtotal : 1.0;

        foreach ($doc->items as $item) {
            $product = $products[$item->product_id];
            $qty = Dec::units($item->quantity, 3);
            $price = Dec::units($item->price, 2) / 100;
            $netUnit = round($price * $ratio, 4);

            $stockBefore = Dec::units(WarehouseStock::where('product_id', $product->id)->sum('quantity'), 3);
            $oldAvg = (float) $product->avg_cost;
            $newAvg = $stockBefore > 0
                ? round((($stockBefore / 1000) * $oldAvg + ($qty / 1000) * $netUnit) / (($stockBefore + $qty) / 1000), 4)
                : $netUnit;

            $product->forceFill(['avg_cost' => $newAvg, 'purchase_price' => $price])->save();
            $item->update(['unit_cost' => $netUnit]);
            $this->move($doc, (int) $doc->warehouse_id, $product, $qty, $moveType, $netUnit, $user->id);
            $this->receiveDocumentBatch($doc, $item, $product, $qty, $netUnit);
        }

        $doc->forceFill(['total' => Dec::str($total, 2)]);
    }

    private function applyPurchaseReturn(StockDocument $doc, Collection $products, User $user): void
    {
        $parent = StockDocument::whereKey($doc->parent_id)->lockForUpdate()->first();
        if (! $parent || $parent->type !== 'purchase' || $parent->status !== 'approved') {
            throw new BusinessRuleException('Asl xarid hujjati tasdiqlangan holatda emas.');
        }
        $parent->load('items');

        foreach ($doc->items as $item) {
            $product = $products[$item->product_id];
            $qty = Dec::units($item->quantity, 3);
            $parentItem = $parent->items->firstWhere('product_id', $product->id);
            if (! $parentItem) {
                throw new BusinessRuleException("«{$product->name}» asl xarid hujjatida yo‘q.");
            }

            $returned = Dec::units(
                StockDocumentItem::where('product_id', $product->id)
                    ->whereHas('document', fn ($q) => $q->where('parent_id', $parent->id)->where('type', 'purchase_return')->where('status', 'approved'))
                    ->sum('quantity'),
                3
            );
            $left = Dec::units($parentItem->quantity, 3) - $returned;
            if ($qty > $left) {
                throw new BusinessRuleException("«{$product->name}»: qaytarish mumkin bo‘lgan miqdor ".Dec::str(max($left, 0), 3).'.');
            }

            $cost = (float) $parentItem->unit_cost;
            $item->update(['unit_cost' => $cost]);
            $this->move($doc, (int) $doc->warehouse_id, $product, -$qty, 'purchase_return', $cost, $user->id);
            $preferredBatch = trim((string) ($parentItem->batch_number ?? '')) ?: ($parent->number.'-'.$parentItem->id);
            $this->consumeBatches((int) $doc->warehouse_id, $product, $qty, 'purchase_return', $doc->id, null, $preferredBatch);
        }
    }

    private function applyIssue(StockDocument $doc, Collection $products, User $user): void
    {
        foreach ($doc->items as $item) {
            $product = $products[$item->product_id];
            $cost = (float) $product->avg_cost;
            $item->update(['unit_cost' => $cost]);
            $qty = Dec::units($item->quantity, 3);
            $this->move($doc, (int) $doc->warehouse_id, $product, -$qty, 'issue', $cost, $user->id);
            $this->consumeBatches((int) $doc->warehouse_id, $product, $qty, 'issue', $doc->id, null);
        }
    }

    private function applyTransfer(StockDocument $doc, Collection $products, User $user): void
    {
        if ($doc->warehouse_id === $doc->to_warehouse_id) {
            throw new BusinessRuleException('Qaysi ombordan va qaysi omborga ko‘chirish bir xil bo‘lishi mumkin emas.');
        }
        foreach ($doc->items as $item) {
            $product = $products[$item->product_id];
            $qty = Dec::units($item->quantity, 3);
            $cost = (float) $product->avg_cost;
            $item->update(['unit_cost' => $cost]);
            $this->move($doc, (int) $doc->warehouse_id, $product, -$qty, 'transfer_out', $cost, $user->id);
            $allocations = $this->consumeBatches((int) $doc->warehouse_id, $product, $qty, 'transfer_out', $doc->id, null);
            $this->move($doc, (int) $doc->to_warehouse_id, $product, $qty, 'transfer_in', $cost, $user->id);
            $this->receiveTransferBatches($doc, $product, (int) $doc->to_warehouse_id, $allocations);
        }
    }

    /** Hisob tasdiqlash paytidagi tizim qoldig'iga nisbatan: farq = sanalgan - tizim. Faqat sanalgan mahsulotlar o'zgaradi. */
    private function applyInventory(StockDocument $doc, Collection $products, User $user): void
    {
        foreach ($doc->items as $item) {
            $product = $products[$item->product_id];
            $this->ensureStockRow((int) $doc->warehouse_id, $product->id);
            $stock = WarehouseStock::where('warehouse_id', $doc->warehouse_id)->where('product_id', $product->id)->lockForUpdate()->first();

            $current = Dec::units($stock->quantity, 3);
            $diff = Dec::units($item->quantity, 3) - $current;
            $cost = (float) $product->avg_cost;
            $item->update(['system_qty' => Dec::str($current, 3), 'diff_qty' => Dec::str($diff, 3), 'unit_cost' => $cost]);

            if ($diff !== 0) {
                $this->move($doc, (int) $doc->warehouse_id, $product, $diff, $diff > 0 ? 'inventory_gain' : 'inventory_loss', $cost, $user->id);
                if ($diff < 0) $this->consumeBatches((int) $doc->warehouse_id, $product, abs($diff), 'inventory_loss', $doc->id, null);
                else $this->receiveAdjustmentBatch($doc, $product, (int) $doc->warehouse_id, $diff, $cost);
            }
        }
    }

    // ---------------------------------------------------------------- yordamchilar

    /** Ombor hujjati bo'yicha harakat (yagona joyga — applyMove — yo'naltiriladi). */
    private function move(StockDocument $doc, int $warehouseId, Product $product, int $change3, string $type, ?float $unitCost, ?int $userId): void
    {
        $this->applyMove($warehouseId, $product, $change3, $type, $unitCost, $userId, $doc->id, null);
    }

    /**
     * Savdo (6-bosqich) uchun harakat: sotuv, qaytarish, bekor qilish. Qoldiq baribir shu servis orqali o'zgaradi.
     * Chaqiruvchi DB tranzaksiyasi ichida bo'lishi va mahsulotni lockProducts() bilan qulflagan bo'lishi shart.
     */
    public function moveForSale(Sale $sale, int $warehouseId, Product $product, int $change3, string $type, ?float $unitCost, ?int $userId): void
    {
        $this->applyMove($warehouseId, $product, $change3, $type, $unitCost, $userId, null, $sale->id);
        $qty = abs($change3);
        if ($type === 'sale' && $change3 < 0) {
            $this->consumeBatches($warehouseId, $product, $qty, 'sale', null, $sale->id);
        } elseif (in_array($type, ['sale_return', 'reversal'], true) && $change3 > 0) {
            $this->restoreSaleBatches($sale, $warehouseId, $product, $qty, $type);
        }
    }

    /** Qoldiqni o'zgartirishning yagona joyi: qator qulflanadi, manfiy qoldiqqa yo'l qo'yilmaydi, harakat yoziladi. */
    private function applyMove(int $warehouseId, Product $product, int $change3, string $type, ?float $unitCost, ?int $userId, ?int $documentId, ?int $saleId): void
    {
        $this->ensureStockRow($warehouseId, $product->id);
        $stock = WarehouseStock::where('warehouse_id', $warehouseId)->where('product_id', $product->id)->lockForUpdate()->first();

        $current = Dec::units($stock->quantity, 3);
        $balance = $current + $change3;
        if ($balance < 0) {
            throw new BusinessRuleException(sprintf(
                '«%s»: ombor qoldig‘i yetarli emas (bor: %s, kerak: %s).',
                $product->name, Dec::str($current, 3), Dec::str(-$change3, 3)
            ));
        }

        $stock->quantity = Dec::str($balance, 3);
        $stock->save();

        StockMovement::create([
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'stock_document_id' => $documentId,
            'sale_id' => $saleId,
            'type' => $type,
            'quantity' => Dec::str($change3, 3),
            'balance_after' => Dec::str($balance, 3),
            'unit_cost' => $unitCost,
            'user_id' => $userId,
        ]);
    }

    private function receiveAdjustmentBatch(StockDocument $doc, Product $product, int $warehouseId, int $qty3, float $unitCost): void
    {
        $number = 'ADJ-'.$doc->number.'-'.$product->id;
        $batch = ProductBatch::firstOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'batch_number' => $number], ['received_at' => $doc->doc_date, 'expires_at' => null, 'quantity_received' => 0, 'quantity_remaining' => 0, 'unit_cost' => $unitCost, 'source_type' => 'inventory_gain', 'source_id' => $doc->id]);
        $batch->quantity_received = Dec::str(Dec::units($batch->quantity_received, 3) + $qty3, 3);
        $batch->quantity_remaining = Dec::str(Dec::units($batch->quantity_remaining, 3) + $qty3, 3); $batch->save();
        DB::table('product_batch_movements')->insert(['product_batch_id' => $batch->id, 'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'stock_document_id' => $doc->id, 'sale_id' => null, 'movement_type' => 'inventory_gain', 'quantity_delta' => Dec::str($qty3, 3), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function reverseDocumentBatches(StockDocument $doc): void
    {
        $moves = DB::table('product_batch_movements')->where('stock_document_id', $doc->id)->where('movement_type', '!=', 'reversal')->orderByDesc('id')->get();
        foreach ($moves as $move) {
            $batch = ProductBatch::whereKey($move->product_batch_id)->lockForUpdate()->first();
            if (!$batch) continue;
            $delta = -Dec::units($move->quantity_delta, 3);
            $remaining = Dec::units($batch->quantity_remaining, 3) + $delta;
            if ($remaining < 0) throw new BusinessRuleException('Hujjatni bekor qilib bo‘lmaydi: partiya qoldig‘i keyingi savdolarda ishlatilgan.');
            $batch->quantity_remaining = Dec::str($remaining, 3);
            if (in_array($move->movement_type, ['purchase', 'opening', 'transfer_in', 'inventory_gain', 'legacy_opening'], true) && Dec::units($move->quantity_delta, 3) > 0) {
                $batch->quantity_received = Dec::str(max(0, Dec::units($batch->quantity_received, 3) - Dec::units($move->quantity_delta, 3)), 3);
            }
            $batch->save();
            DB::table('product_batch_movements')->insert(['product_batch_id' => $batch->id, 'product_id' => $move->product_id, 'warehouse_id' => $move->warehouse_id, 'stock_document_id' => $doc->id, 'sale_id' => null, 'movement_type' => 'reversal', 'quantity_delta' => Dec::str($delta, 3), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function receiveDocumentBatch(StockDocument $doc, StockDocumentItem $item, Product $product, int $qty3, float $unitCost): void
    {
        $number = trim((string) $item->batch_number) ?: ($doc->number.'-'.$item->id);
        $batch = ProductBatch::where('product_id', $product->id)->where('warehouse_id', $doc->warehouse_id)->where('batch_number', $number)->lockForUpdate()->first();
        $itemExpiry = $item->expires_at ? $item->expires_at->toDateString() : null;
        if ($batch && $batch->expires_at && $itemExpiry && $batch->expires_at->toDateString() !== $itemExpiry) {
            throw new BusinessRuleException('Bir xil partiya raqami uchun yaroqlilik muddati farq qilmoqda: '.$number.'.');
        }
        if (!$batch) $batch = ProductBatch::create(['product_id' => $product->id, 'warehouse_id' => $doc->warehouse_id, 'batch_number' => $number, 'received_at' => $item->received_at ?? $doc->doc_date, 'expires_at' => $item->expires_at, 'quantity_received' => 0, 'quantity_remaining' => 0, 'unit_cost' => $unitCost, 'source_type' => $doc->type, 'source_id' => $doc->id]);
        elseif (!$batch->expires_at && $item->expires_at) $batch->expires_at = $item->expires_at;
        $batch->quantity_received = Dec::str(Dec::units($batch->quantity_received, 3) + $qty3, 3);
        $batch->quantity_remaining = Dec::str(Dec::units($batch->quantity_remaining, 3) + $qty3, 3);
        $batch->unit_cost = $unitCost;
        $batch->save();
        DB::table('product_batch_movements')->insert(['product_batch_id' => $batch->id, 'product_id' => $product->id, 'warehouse_id' => $doc->warehouse_id, 'stock_document_id' => $doc->id, 'sale_id' => null, 'movement_type' => $doc->type, 'quantity_delta' => Dec::str($qty3, 3), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** FEFO allocation: earliest expiry is depleted first. Legacy stock is backfilled by migration. */
    private function consumeBatches(int $warehouseId, Product $product, int $qty3, string $type, ?int $documentId, ?int $saleId, ?string $preferredBatchNumber = null): array
    {
        $remaining = $qty3; $allocations = [];
        $batches = ProductBatch::where('warehouse_id', $warehouseId)->where('product_id', $product->id)
            ->when($preferredBatchNumber !== null, fn($q) => $q->where('batch_number', $preferredBatchNumber))
            ->when($type === 'sale', fn($q) => $q->where(fn($expiry) => $expiry->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString())))
            ->where('quantity_remaining', '>', 0)
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $available = Dec::units($batch->quantity_remaining, 3);
            if ($available <= 0) continue;
            $take = min($available, $remaining);
            $batch->quantity_remaining = Dec::str($available - $take, 3);
            $batch->save();
            DB::table('product_batch_movements')->insert(['product_batch_id' => $batch->id, 'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'stock_document_id' => $documentId, 'sale_id' => $saleId, 'movement_type' => $type, 'quantity_delta' => Dec::str(-$take, 3), 'created_at' => now(), 'updated_at' => now()]);
            $allocations[] = ['batch' => $batch, 'qty' => $take]; $remaining -= $take;
        }
        if ($remaining > 0) throw new BusinessRuleException('Partiya qoldig‘i yetarli emas: '.$product->name.'. FEFO partiyalarini tekshiring.');
        return $allocations;
    }

    private function receiveTransferBatches(StockDocument $doc, Product $product, int $warehouseId, array $allocations): void
    {
        foreach ($allocations as $allocation) {
            /** @var ProductBatch $source */ $source = $allocation['batch']; $qty = $allocation['qty'];
            $batch = ProductBatch::where('product_id', $product->id)->where('warehouse_id', $warehouseId)->where('batch_number', $source->batch_number)->lockForUpdate()->first();
            if ($batch && $batch->expires_at && $source->expires_at && $batch->expires_at->toDateString() !== $source->expires_at->toDateString()) throw new BusinessRuleException('Ko‘chirishdagi partiya yaroqlilik muddati qabul qiluvchi ombordagi partiya bilan mos emas.');
            if (!$batch) $batch = ProductBatch::create(['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'batch_number' => $source->batch_number, 'received_at' => $source->received_at, 'expires_at' => $source->expires_at, 'quantity_received' => 0, 'quantity_remaining' => 0, 'unit_cost' => $source->unit_cost, 'source_type' => 'transfer', 'source_id' => $doc->id]);
            $batch->quantity_received = Dec::str(Dec::units($batch->quantity_received, 3) + $qty, 3);
            $batch->quantity_remaining = Dec::str(Dec::units($batch->quantity_remaining, 3) + $qty, 3);
            $batch->save();
            DB::table('product_batch_movements')->insert(['product_batch_id' => $batch->id, 'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'stock_document_id' => $doc->id, 'sale_id' => null, 'movement_type' => 'transfer_in', 'quantity_delta' => Dec::str($qty, 3), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function restoreSaleBatches(Sale $sale, int $warehouseId, Product $product, int $qty3, string $type): void
    {
        $originalSaleId = $sale->type === 'return' && $sale->parent_id ? (int) $sale->parent_id : (int) $sale->id;
        $outgoing = DB::table('product_batch_movements')->where('sale_id', $originalSaleId)->where('product_id', $product->id)->where('movement_type', 'sale')->where('quantity_delta', '<', 0)->orderBy('id')->get();
        if ($outgoing->isEmpty()) {
            // Old sales created before lot tracking: keep stock traceable by receiving a dedicated return lot.
            $batch = ProductBatch::firstOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'batch_number' => strtoupper($type).'-'.$sale->number.'-'.$product->id], ['received_at' => now()->toDateString(), 'expires_at' => null, 'quantity_received' => 0, 'quantity_remaining' => 0, 'unit_cost' => 0, 'source_type' => $type, 'source_id' => $sale->id]);
            $batch->quantity_received = Dec::str(Dec::units($batch->quantity_received, 3) + $qty3, 3);
            $batch->quantity_remaining = Dec::str(Dec::units($batch->quantity_remaining, 3) + $qty3, 3); $batch->save();
            DB::table('product_batch_movements')->insert(['product_batch_id' => $batch->id, 'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'stock_document_id' => null, 'sale_id' => $sale->id, 'movement_type' => $type, 'quantity_delta' => Dec::str($qty3, 3), 'created_at' => now(), 'updated_at' => now()]);
            return;
        }
        $relatedSaleIds = Sale::where('id', $originalSaleId)->orWhere('parent_id', $originalSaleId)->pluck('id');
        $restored = (int) round((float) DB::table('product_batch_movements')->whereIn('sale_id', $relatedSaleIds)->where('product_id', $product->id)->whereIn('movement_type', ['sale_return', 'reversal'])->where('quantity_delta', '>', 0)->sum('quantity_delta') * 1000);
        $skip = $restored; $remaining = $qty3;
        foreach ($outgoing as $movement) {
            if ($remaining <= 0) break;
            $taken = abs(Dec::units($movement->quantity_delta, 3));
            if ($skip >= $taken) { $skip -= $taken; continue; }
            $availableToRestore = $taken - $skip; $skip = 0; $restore = min($remaining, $availableToRestore);
            $batch = ProductBatch::whereKey($movement->product_batch_id)->lockForUpdate()->first();
            if (!$batch) continue;
            $batch->quantity_remaining = Dec::str(Dec::units($batch->quantity_remaining, 3) + $restore, 3);
            $batch->quantity_received = Dec::str(Dec::units($batch->quantity_received, 3) + $restore, 3); $batch->save();
            DB::table('product_batch_movements')->insert(['product_batch_id' => $batch->id, 'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'stock_document_id' => null, 'sale_id' => $sale->id, 'movement_type' => $type, 'quantity_delta' => Dec::str($restore, 3), 'created_at' => now(), 'updated_at' => now()]);
            $remaining -= $restore;
        }
        if ($remaining > 0) throw new BusinessRuleException('Qaytarilayotgan miqdor asl savdoning partiya qoldig‘idan oshib ketdi.');
    }

    private function ensureStockRow(int $warehouseId, int $productId): void
    {
        WarehouseStock::insertOrIgnore([
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Mahsulotlar id bo'yicha tartibda qulflanadi (bir vaqtdagi hujjatlar o'zaro tiqilib qolmasligi uchun). */
    public function lockProducts(array $ids, array $with = []): Collection
    {
        return Product::withTrashed()->with($with)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function assertWarehouseActive(int $id): void
    {
        $w = Warehouse::find($id);
        if (! $w || ! $w->is_active) {
            throw new BusinessRuleException('Ombor topilmadi yoki faol emas.');
        }
    }

    private function fresh(StockDocument $doc): StockDocument
    {
        return $doc->fresh(['items.product.unit', 'warehouse', 'toWarehouse', 'supplier', 'parent:id,number', 'creator:id,name', 'approver:id,name']);
    }
}
