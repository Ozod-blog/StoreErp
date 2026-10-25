<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function api($user)
    {
        return $this->withHeaders($this->bearer($user));
    }

    private function product(string $sku = 'P-1', bool $fraction = false): Product
    {
        $unit = Unit::firstOrCreate(
            ['short_name' => $fraction ? 'kg' : 'dona'],
            ['name' => $fraction ? 'Kilogramm' : 'Dona', 'allow_fraction' => $fraction]
        );

        return Product::create(['sku' => $sku, 'name' => "Mahsulot $sku", 'unit_id' => $unit->id, 'sale_price' => 2000]);
    }

    private function warehouse(string $code = 'W-1'): Warehouse
    {
        return Warehouse::create(['code' => $code, 'name' => "Ombor $code"]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['name' => 'Test yetkazib beruvchi']);
    }

    private function stock(Warehouse $w, Product $p): float
    {
        return (float) (WarehouseStock::where('warehouse_id', $w->id)->where('product_id', $p->id)->value('quantity') ?? 0);
    }

    private function purchaseBody(Warehouse $w, Supplier $s, array $items, array $extra = []): array
    {
        return $extra + [
            'type' => 'purchase', 'warehouse_id' => $w->id, 'supplier_id' => $s->id,
            'doc_date' => '2026-10-12', 'items' => $items,
        ];
    }

    /** Qoralama xarid yaratib, tasdiqlaydi va hujjat id sini qaytaradi. */
    private function approvedPurchase($admin, Warehouse $w, Supplier $s, Product $p, float $qty, float $price, array $extra = []): int
    {
        $id = $this->api($admin)->postJson('/api/v1/purchases', $this->purchaseBody($w, $s, [
            ['product_id' => $p->id, 'quantity' => $qty, 'price' => $price],
        ], $extra))->assertStatus(201)->json('id');
        $this->api($admin)->postJson("/api/v1/purchases/$id/approve")->assertOk();

        return $id;
    }

    private function issueDraft($admin, Warehouse $w, Product $p, float $qty): int
    {
        return $this->api($admin)->postJson('/api/v1/stock-documents', [
            'type' => 'issue', 'warehouse_id' => $w->id, 'reason' => 'write_off', 'doc_date' => '2026-10-12',
            'items' => [['product_id' => $p->id, 'quantity' => $qty]],
        ])->assertStatus(201)->json('id');
    }

    public function test_guest_gets_401_and_cashier_gets_403(): void
    {
        $this->getJson('/api/v1/purchases')->assertStatus(401);
        $this->getJson('/api/v1/stock-documents')->assertStatus(401);
        $this->getJson('/api/v1/stock-movements')->assertStatus(401);

        $cashier = $this->makeUser('cashier');
        $this->api($cashier)->getJson('/api/v1/purchases')->assertStatus(403);
        $this->api($cashier)->getJson('/api/v1/stock-documents')->assertStatus(403);
        $this->api($cashier)->getJson('/api/v1/stock-movements')->assertStatus(403);
    }

    public function test_warehouse_role_can_create_draft_but_not_approve(): void
    {
        $keeper = $this->makeUser('warehouse');
        $w = $this->warehouse();
        $p = $this->product();

        $id = $this->api($keeper)->postJson('/api/v1/purchases', $this->purchaseBody($w, $this->supplier(), [
            ['product_id' => $p->id, 'quantity' => 5, 'price' => 1000],
        ]))->assertStatus(201)->json('id');

        $this->api($keeper)->postJson("/api/v1/purchases/$id/approve")->assertStatus(403);
        $this->assertSame('draft', StockDocument::find($id)->status);
        $this->assertSame(0.0, $this->stock($w, $p));
    }

    public function test_draft_does_not_change_stock_and_approval_increases_it_once(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $s = $this->supplier();
        $p = $this->product();

        $id = $this->api($admin)->postJson('/api/v1/purchases', $this->purchaseBody($w, $s, [
            ['product_id' => $p->id, 'quantity' => 10, 'price' => 1000],
        ]))->assertStatus(201)->assertJsonPath('status', 'draft')->json('id');

        $this->assertSame(0.0, $this->stock($w, $p), 'Qoralama qoldiqqa ta’sir qilmasligi kerak');

        $this->api($admin)->postJson("/api/v1/purchases/$id/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(10.0, $this->stock($w, $p));

        // Qayta yuborish ikkinchi marta qoldiq qo'shmaydi.
        $this->api($admin)->postJson("/api/v1/purchases/$id/approve")->assertStatus(422);
        $this->assertSame(10.0, $this->stock($w, $p));
        $this->assertSame(1, StockMovement::where('stock_document_id', $id)->count());
        $this->assertTrue(AuditLog::where('action', 'stock_documents.approve')->where('auditable_id', $id)->where('result', 'success')->exists());
    }

    public function test_weighted_average_cost_and_discount(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $s = $this->supplier();
        $p = $this->product();

        $this->approvedPurchase($admin, $w, $s, $p, 10, 1000);
        $this->assertEquals(1000, (float) $p->fresh()->avg_cost);

        $this->approvedPurchase($admin, $w, $s, $p, 10, 2000);
        $this->assertEquals(1500, (float) $p->fresh()->avg_cost);

        // Chegirma: 10 x 1000 = 10000, chegirma 1000 => jami 9000, sof narx 900.
        $q = $this->product('P-2');
        $id = $this->approvedPurchase($admin, $w, $s, $q, 10, 1000, ['discount' => 1000, 'paid_amount' => 4000, 'payment_method' => 'cash']);
        $this->assertEquals(900, (float) $q->fresh()->avg_cost);
        $this->assertEquals(9000, (float) StockDocument::find($id)->total);
        $this->assertEquals(4000, (float) StockDocument::find($id)->paid_amount);
    }

    public function test_paid_amount_cannot_exceed_total(): void
    {
        $admin = $this->makeUser('admin');
        $p = $this->product();

        $this->api($admin)->postJson('/api/v1/purchases', $this->purchaseBody($this->warehouse(), $this->supplier(), [
            ['product_id' => $p->id, 'quantity' => 1, 'price' => 1000],
        ], ['paid_amount' => 5000, 'payment_method' => 'cash']))->assertStatus(422);
    }

    public function test_issue_cannot_make_stock_negative_and_failure_changes_nothing(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $p = $this->product();
        $this->approvedPurchase($admin, $w, $this->supplier(), $p, 3, 1000);

        $id = $this->issueDraft($admin, $w, $p, 5);
        $this->api($admin)->postJson("/api/v1/stock-documents/$id/approve")->assertStatus(422);

        $this->assertSame(3.0, $this->stock($w, $p));
        $this->assertSame('draft', StockDocument::find($id)->status);
        $this->assertTrue(AuditLog::where('action', 'stock_documents.approve')->where('result', 'failed')->exists());
    }

    public function test_issue_decreases_stock(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $p = $this->product();
        $this->approvedPurchase($admin, $w, $this->supplier(), $p, 10, 1000);

        $id = $this->issueDraft($admin, $w, $p, 4);
        $this->api($admin)->postJson("/api/v1/stock-documents/$id/approve")->assertOk();

        $this->assertSame(6.0, $this->stock($w, $p));
        $this->assertEquals(-4, (float) StockMovement::where('stock_document_id', $id)->value('quantity'));
    }

    public function test_transfer_moves_stock_between_warehouses(): void
    {
        $admin = $this->makeUser('admin');
        $a = $this->warehouse('W-A');
        $b = $this->warehouse('W-B');
        $p = $this->product();
        $this->approvedPurchase($admin, $a, $this->supplier(), $p, 10, 1000);

        $id = $this->api($admin)->postJson('/api/v1/stock-documents', [
            'type' => 'transfer', 'warehouse_id' => $a->id, 'to_warehouse_id' => $b->id, 'doc_date' => '2026-10-12',
            'items' => [['product_id' => $p->id, 'quantity' => 4]],
        ])->assertStatus(201)->json('id');
        $this->api($admin)->postJson("/api/v1/stock-documents/$id/approve")->assertOk();

        $this->assertSame(6.0, $this->stock($a, $p));
        $this->assertSame(4.0, $this->stock($b, $p));
        $this->assertSame(10.0, $this->stock($a, $p) + $this->stock($b, $p), 'Jami qoldiq o‘zgarmaydi');
    }

    public function test_transfer_to_same_warehouse_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $a = $this->warehouse('W-A');
        $p = $this->product();

        $this->api($admin)->postJson('/api/v1/stock-documents', [
            'type' => 'transfer', 'warehouse_id' => $a->id, 'to_warehouse_id' => $a->id, 'doc_date' => '2026-10-12',
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_inventory_sets_actual_quantity_and_records_difference(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $p = $this->product();
        $this->approvedPurchase($admin, $w, $this->supplier(), $p, 10, 1000);

        $id = $this->api($admin)->postJson('/api/v1/stock-documents', [
            'type' => 'inventory', 'warehouse_id' => $w->id, 'doc_date' => '2026-10-12',
            'items' => [['product_id' => $p->id, 'quantity' => 7]],
        ])->assertStatus(201)->json('id');
        $this->api($admin)->postJson("/api/v1/stock-documents/$id/approve")->assertOk();

        $this->assertSame(7.0, $this->stock($w, $p));
        $m = StockMovement::where('stock_document_id', $id)->first();
        $this->assertSame('inventory_loss', $m->type);
        $this->assertEquals(-3, (float) $m->quantity);
        $this->assertEquals(-3, (float) StockDocument::find($id)->items()->first()->diff_qty);
    }

    public function test_purchase_return_is_limited_to_purchased_quantity(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $p = $this->product();
        $purchaseId = $this->approvedPurchase($admin, $w, $this->supplier(), $p, 10, 1000);

        $returnDraft = fn (float $qty) => $this->api($admin)->postJson('/api/v1/purchases', [
            'type' => 'purchase_return', 'parent_id' => $purchaseId, 'doc_date' => '2026-10-12',
            'items' => [['product_id' => $p->id, 'quantity' => $qty]],
        ])->assertStatus(201)->json('id');

        $first = $returnDraft(4);
        $this->api($admin)->postJson("/api/v1/purchases/$first/approve")->assertOk();
        $this->assertSame(6.0, $this->stock($w, $p));

        $second = $returnDraft(7); // 10 - 4 = 6 qolgan
        $this->api($admin)->postJson("/api/v1/purchases/$second/approve")->assertStatus(422);
        $this->assertSame(6.0, $this->stock($w, $p));

        $this->api($admin)->getJson("/api/v1/purchases/$purchaseId/returnable")
            ->assertOk()->assertJsonPath('items.0.remaining', '6.000');
    }

    public function test_cancel_reverses_stock_and_is_blocked_when_stock_was_consumed(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $p = $this->product();
        $purchaseId = $this->approvedPurchase($admin, $w, $this->supplier(), $p, 10, 1000);

        $issueId = $this->issueDraft($admin, $w, $p, 3);
        $this->api($admin)->postJson("/api/v1/stock-documents/$issueId/approve")->assertOk();

        // Qoldiq 7, xarid 10 — bekor qilib bo'lmaydi.
        $this->api($admin)->postJson("/api/v1/purchases/$purchaseId/cancel", ['reason' => 'Xato kiritilgan'])->assertStatus(422);
        $this->assertSame(7.0, $this->stock($w, $p));
        $this->assertSame('approved', StockDocument::find($purchaseId)->status);

        // Sababsiz bekor qilish mumkin emas.
        $this->api($admin)->postJson("/api/v1/stock-documents/$issueId/cancel")->assertStatus(422);

        $this->api($admin)->postJson("/api/v1/stock-documents/$issueId/cancel", ['reason' => 'Xato chiqim'])->assertOk();
        $this->assertSame(10.0, $this->stock($w, $p));

        $this->api($admin)->postJson("/api/v1/purchases/$purchaseId/cancel", ['reason' => 'Xato kiritilgan'])->assertOk();
        $this->assertSame(0.0, $this->stock($w, $p));
        $this->assertSame('cancelled', StockDocument::find($purchaseId)->status);
        $this->assertSame(2, StockMovement::where('stock_document_id', $purchaseId)->count(), 'Tarix o‘chirilmaydi: asl + teskari harakat');

        // Bekor qilingan hujjatni qayta tasdiqlab bo'lmaydi.
        $this->api($admin)->postJson("/api/v1/purchases/$purchaseId/approve")->assertStatus(422);
        $this->assertSame(0.0, $this->stock($w, $p));
    }

    public function test_purchase_with_approved_return_cannot_be_cancelled(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $p = $this->product();
        $purchaseId = $this->approvedPurchase($admin, $w, $this->supplier(), $p, 10, 1000);

        $ret = $this->api($admin)->postJson('/api/v1/purchases', [
            'type' => 'purchase_return', 'parent_id' => $purchaseId, 'doc_date' => '2026-10-12',
            'items' => [['product_id' => $p->id, 'quantity' => 2]],
        ])->assertStatus(201)->json('id');
        $this->api($admin)->postJson("/api/v1/purchases/$ret/approve")->assertOk();

        $this->api($admin)->postJson("/api/v1/purchases/$purchaseId/cancel", ['reason' => 'Xato'])->assertStatus(422);
        $this->assertSame(8.0, $this->stock($w, $p));
    }

    public function test_create_is_idempotent_with_client_token(): void
    {
        $admin = $this->makeUser('admin');
        $body = $this->purchaseBody($this->warehouse(), $this->supplier(), [
            ['product_id' => $this->product()->id, 'quantity' => 1, 'price' => 100],
        ], ['client_token' => 'token-123']);

        $first = $this->api($admin)->postJson('/api/v1/purchases', $body)->assertStatus(201)->json('id');
        $second = $this->api($admin)->postJson('/api/v1/purchases', $body)->assertOk()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, StockDocument::count());
    }

    public function test_approved_document_cannot_be_edited(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $s = $this->supplier();
        $p = $this->product();
        $id = $this->approvedPurchase($admin, $w, $s, $p, 5, 1000);

        $this->api($admin)->putJson("/api/v1/purchases/$id", $this->purchaseBody($w, $s, [
            ['product_id' => $p->id, 'quantity' => 99, 'price' => 1000],
        ]))->assertStatus(422);
        $this->assertSame(5.0, $this->stock($w, $p));
    }

    public function test_draft_can_be_edited(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $s = $this->supplier();
        $p = $this->product();
        $id = $this->api($admin)->postJson('/api/v1/purchases', $this->purchaseBody($w, $s, [
            ['product_id' => $p->id, 'quantity' => 5, 'price' => 1000],
        ]))->json('id');

        $this->api($admin)->putJson("/api/v1/purchases/$id", $this->purchaseBody($w, $s, [
            ['product_id' => $p->id, 'quantity' => 8, 'price' => 1000],
        ]))->assertOk();

        $this->assertEquals(8, (float) StockDocument::find($id)->items()->first()->quantity);
        $this->assertEquals(8000, (float) StockDocument::find($id)->total);
    }

    public function test_fractional_quantity_requires_fractional_unit(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $s = $this->supplier();

        $this->api($admin)->postJson('/api/v1/purchases', $this->purchaseBody($w, $s, [
            ['product_id' => $this->product('P-DONA')->id, 'quantity' => 1.5, 'price' => 100],
        ]))->assertStatus(422);

        $this->api($admin)->postJson('/api/v1/purchases', $this->purchaseBody($w, $s, [
            ['product_id' => $this->product('P-KG', true)->id, 'quantity' => 1.5, 'price' => 100],
        ]))->assertStatus(201);
    }

    public function test_document_type_is_bound_to_its_endpoint(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $p = $this->product();
        $issueId = $this->issueDraft($admin, $w, $p, 1);

        // Chiqim hujjati xarid endpointida ko'rinmaydi va tasdiqlanmaydi.
        $this->api($admin)->getJson("/api/v1/purchases/$issueId")->assertStatus(404);
        $this->api($admin)->postJson("/api/v1/purchases/$issueId/approve")->assertStatus(404);
        $this->api($admin)->postJson('/api/v1/purchases', [
            'type' => 'issue', 'warehouse_id' => $w->id, 'reason' => 'other', 'doc_date' => '2026-10-12',
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_movements_endpoint_lists_history(): void
    {
        $admin = $this->makeUser('admin');
        $w = $this->warehouse();
        $p = $this->product();
        $this->approvedPurchase($admin, $w, $this->supplier(), $p, 5, 1000);

        $this->api($admin)->getJson('/api/v1/stock-movements?warehouse_id='.$w->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.type', 'purchase');
    }
}
