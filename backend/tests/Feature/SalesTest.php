<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cashbox;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    private Cashbox $box;

    private function api($user)
    {
        return $this->withHeaders($this->bearer($user));
    }

    /** Ombor + kassa + manager (smena ochiq, boshlang'ich naqd 0). */
    private function shop(string $role = 'manager', float $opening = 0, array $userAttrs = []): \App\Models\User
    {
        $user = $this->makeUser($role, $userAttrs);
        $this->wh ??= Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        $this->box ??= Cashbox::create(['code' => 'K-1', 'name' => 'Kassa 1']);
        $this->api($user)->postJson('/api/v1/shifts/open', ['cashbox_id' => $this->box->id, 'opening_cash' => $opening])->assertStatus(201);

        return $user;
    }

    private function product(string $sku, float $price, float $cost, float $qty, string $unit = 'dona', array $extra = []): Product
    {
        $this->wh ??= Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        $u = Unit::firstOrCreate(['short_name' => $unit], ['name' => ucfirst($unit), 'allow_fraction' => $unit !== 'dona']);
        $p = Product::create($extra + ['sku' => $sku, 'name' => "Mahsulot $sku", 'unit_id' => $u->id, 'sale_price' => $price]);
        $p->forceFill(['avg_cost' => $cost])->save();
        WarehouseStock::create(['warehouse_id' => $this->wh->id, 'product_id' => $p->id, 'quantity' => $qty]);

        return $p;
    }

    private function stockOf(Product $p): float
    {
        return (float) WarehouseStock::where('warehouse_id', $this->wh->id)->where('product_id', $p->id)->value('quantity');
    }

    private function cash(): float
    {
        return app(CashService::class)->balanceUnits($this->box->id) / 100;
    }

    private function sell($user, array $items, array $payments = [], array $extra = [])
    {
        return $this->api($user)->postJson('/api/v1/sales', $extra + ['items' => $items, 'payments' => $payments]);
    }

    public function test_cash_sale_reduces_stock_writes_cash_ledger_and_freezes_cost(): void
    {
        $user = $this->shop('manager', 100000);
        $p = $this->product('A1', 12000, 7000, 10);

        $res = $this->sell($user, [['product_id' => $p->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 24000]])
            ->assertStatus(201)
            ->assertJsonPath('type', 'sale')->assertJsonPath('status', 'approved')
            ->assertJsonPath('total', '24000.00')->assertJsonPath('paid_amount', '24000.00')->assertJsonPath('debt_amount', '0.00')
            ->assertJsonPath('cogs', '14000.00');

        $this->assertStringStartsWith('SV-', $res->json('number'));
        $this->assertSame(8.0, $this->stockOf($p));
        $this->assertEquals(124000, $this->cash());
        $this->assertSame('7000.0000', $res->json('items.0.unit_cost'));

        $tx = CashTransaction::where('category', 'sale')->first();
        $this->assertSame('in', $tx->direction);
        $this->assertSame('sale', $tx->source_type);
        $this->assertTrue(StockMovement::where('type', 'sale')->where('sale_id', $res->json('id'))->exists());
        $this->assertTrue(AuditLog::where('action', 'sales.create')->where('result', 'success')->exists());

        $shiftId = $this->api($user)->getJson('/api/v1/shifts/current')->json('shift.id');
        $this->api($user)->getJson("/api/v1/shifts/$shiftId")
            ->assertJsonPath('summary.expected_cash', '124000.00')
            ->assertJsonPath('sales_summary.sales_count', 1)
            ->assertJsonPath('sales_summary.sales_total', '24000.00');
    }

    public function test_sale_requires_open_shift(): void
    {
        $user = $this->shop('manager');
        $p = $this->product('A1', 1000, 500, 5);
        $this->api($user)->postJson('/api/v1/shifts/' . $this->api($user)->getJson('/api/v1/shifts/current')->json('shift.id') . '/close', ['actual_cash' => 0])->assertOk();

        $this->sell($user, [['product_id' => $p->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]])->assertStatus(422);
        $this->assertSame(5.0, $this->stockOf($p));
        $this->assertSame(0, Sale::count());
    }

    public function test_insufficient_stock_rolls_back_everything(): void
    {
        $user = $this->shop();
        $a = $this->product('A1', 1000, 500, 5);
        $b = $this->product('B1', 2000, 800, 1);

        $this->sell($user, [['product_id' => $a->id, 'quantity' => 2], ['product_id' => $b->id, 'quantity' => 3]], [['method' => 'cash', 'amount' => 8000]])
            ->assertStatus(422);

        $this->assertSame(5.0, $this->stockOf($a));
        $this->assertSame(1.0, $this->stockOf($b));
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, CashTransaction::where('category', 'sale')->count());
    }

    public function test_resubmitting_same_client_token_does_not_duplicate_sale(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 1000, 500, 10);
        $payload = [['product_id' => $p->id, 'quantity' => 1]];

        $first = $this->sell($user, $payload, [['method' => 'cash', 'amount' => 1000]], ['client_token' => 'tok-1'])->assertStatus(201)->json('id');
        $second = $this->sell($user, $payload, [['method' => 'cash', 'amount' => 1000]], ['client_token' => 'tok-1'])->assertOk()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Sale::count());
        $this->assertSame(9.0, $this->stockOf($p));
        $this->assertSame(1, CashTransaction::where('category', 'sale')->count());
    }

    public function test_cashier_cannot_discount_or_change_price_but_manager_can(): void
    {
        $cashier = $this->shop('cashier', 0, ['email' => 'c@test.uz']);
        $p = $this->product('A1', 10000, 4000, 20);
        $item = ['product_id' => $p->id, 'quantity' => 1];

        $this->sell($cashier, [$item + ['discount' => 500]], [['method' => 'cash', 'amount' => 9500]])->assertStatus(403);
        $this->sell($cashier, [$item + ['price' => 9000]], [['method' => 'cash', 'amount' => 9000]])->assertStatus(403);
        $this->sell($cashier, [$item], [['method' => 'cash', 'amount' => 9000]], ['discount' => 1000])->assertStatus(403);
        $this->assertSame(0, Sale::count());

        // Ruxsatsiz urinish ham qoldiqni/kassani o'zgartirmagan; oddiy narxda sotish ishlaydi.
        $this->sell($cashier, [$item], [['method' => 'cash', 'amount' => 10000]])->assertStatus(201);
    }

    public function test_document_discount_is_allocated_exactly_across_lines(): void
    {
        $user = $this->shop();
        $a = $this->product('A1', 1.00, 0.5, 10);
        $b = $this->product('B1', 1.00, 0.5, 10);
        $c = $this->product('C1', 1.00, 0.5, 10);

        $res = $this->sell($user, [
            ['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 1], ['product_id' => $c->id, 'quantity' => 1],
        ], [['method' => 'cash', 'amount' => 2.00]], ['discount' => 1.00])
            ->assertStatus(201)->assertJsonPath('total', '2.00')->assertJsonPath('discount', '1.00')->assertJsonPath('subtotal', '3.00');

        $sum = collect($res->json('items'))->sum(fn ($i) => (float) $i['net_total']);
        $shares = collect($res->json('items'))->sum(fn ($i) => (float) $i['discount_share']);
        $this->assertEqualsWithDelta(2.00, $sum, 0.0001);
        $this->assertEqualsWithDelta(1.00, $shares, 0.0001);
    }

    public function test_debt_sale_needs_permission_and_customer(): void
    {
        $cashier = $this->shop('cashier', 0, ['email' => 'c@test.uz']);
        $p = $this->product('A1', 10000, 4000, 20);
        $customer = Customer::create(['name' => 'Ali Valiyev']);
        $item = [['product_id' => $p->id, 'quantity' => 2]];

        $this->sell($cashier, $item, [['method' => 'cash', 'amount' => 5000]], ['customer_id' => $customer->id])->assertStatus(403);
        $this->assertSame(20.0, $this->stockOf($p));
    }

    public function test_manager_debt_sale_records_unpaid_amount_and_requires_customer(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 10000, 4000, 20);
        $customer = Customer::create(['name' => 'Ali Valiyev']);
        $item = [['product_id' => $p->id, 'quantity' => 2]];

        $this->sell($user, $item, [['method' => 'cash', 'amount' => 5000]])->assertStatus(422); // mijoz tanlanmagan

        $this->sell($user, $item, [['method' => 'cash', 'amount' => 5000]], ['customer_id' => $customer->id])
            ->assertStatus(201)->assertJsonPath('total', '20000.00')->assertJsonPath('paid_amount', '5000.00')->assertJsonPath('debt_amount', '15000.00');
        $this->assertEquals(5000, $this->cash()); // kassaga faqat haqiqiy to'langan summa tushadi

        $this->sell($user, [['product_id' => $p->id, 'quantity' => 1]], [], ['customer_id' => $customer->id])
            ->assertStatus(201)->assertJsonPath('debt_amount', '10000.00'); // to'liq qarzga
    }

    public function test_mixed_payments_split_cash_and_non_cash_in_shift_summary(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 10000, 4000, 20);

        $this->sell($user, [['product_id' => $p->id, 'quantity' => 3]], [
            ['method' => 'cash', 'amount' => 10000],
            ['method' => 'card', 'amount' => 20000],
        ])->assertStatus(201);

        $this->assertEquals(10000, $this->cash()); // karta to'lovi kassa naqd pulini oshirmaydi

        $shiftId = $this->api($user)->getJson('/api/v1/shifts/current')->json('shift.id');
        $s = $this->api($user)->getJson("/api/v1/shifts/$shiftId")->json('sales_summary');
        $this->assertSame('20000.00', $s['non_cash_net']);
        $this->assertCount(2, $s['by_method']);
    }

    public function test_click_and_payme_need_reference_and_are_not_marked_verified(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 10000, 4000, 20);
        $item = [['product_id' => $p->id, 'quantity' => 1]];

        $this->sell($user, $item, [['method' => 'click', 'amount' => 10000]])->assertStatus(422);

        $res = $this->sell($user, $item, [['method' => 'click', 'amount' => 10000, 'reference' => 'CLK-12345']])->assertStatus(201);
        $payment = Payment::where('sale_id', $res->json('id'))->first();
        $this->assertFalse($payment->is_online_verified);
        $this->assertSame('CLK-12345', $payment->reference);
        $this->assertNull($payment->cash_transaction_id);
        $this->assertEquals(0, $this->cash());
    }

    public function test_payments_cannot_exceed_total(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 10000, 4000, 20);

        $this->sell($user, [['product_id' => $p->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 12000]])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_fraction_quantity_only_for_fractional_units(): void
    {
        $user = $this->shop();
        $piece = $this->product('P1', 10000, 4000, 20, 'dona');
        $kg = $this->product('K1', 20000, 9000, 20, 'kg');

        $this->sell($user, [['product_id' => $piece->id, 'quantity' => 1.5]], [['method' => 'cash', 'amount' => 15000]])->assertStatus(422);
        $this->sell($user, [['product_id' => $kg->id, 'quantity' => 1.5]], [['method' => 'cash', 'amount' => 30000]])
            ->assertStatus(201)->assertJsonPath('total', '30000.00');
        $this->assertSame(18.5, $this->stockOf($kg));
    }

    public function test_archived_or_inactive_product_cannot_be_sold(): void
    {
        $user = $this->shop();
        $archived = $this->product('A1', 1000, 500, 5);
        $inactive = $this->product('B1', 1000, 500, 5);
        $inactive->update(['is_active' => false]);
        $archived->delete();

        $this->sell($user, [['product_id' => $archived->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]])->assertStatus(422);
        $this->sell($user, [['product_id' => $inactive->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]])->assertStatus(422);
        $this->assertSame(0, Sale::count());
    }

    public function test_cancel_restores_stock_and_cash_and_keeps_history(): void
    {
        $user = $this->shop('manager', 1000);
        $p = $this->product('A1', 12000, 7000, 10);
        $id = $this->sell($user, [['product_id' => $p->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 24000]])->json('id');

        $this->api($user)->postJson("/api/v1/sales/$id/cancel", [])->assertStatus(422); // sabab majburiy
        $this->api($user)->postJson("/api/v1/sales/$id/cancel", ['reason' => 'Mijoz fikridan qaytdi'])
            ->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(10.0, $this->stockOf($p));
        $this->assertEquals(1000, $this->cash());
        $this->assertTrue(StockMovement::where('sale_id', $id)->where('type', 'reversal')->exists());
        $this->assertTrue(Payment::where('sale_id', $id)->where('kind', 'reversal')->where('direction', 'out')->exists());
        $this->assertTrue(AuditLog::where('action', 'sales.cancel')->where('result', 'success')->exists());
        $this->assertSame(1, Sale::count()); // yozuv o'chirilmagan

        $this->api($user)->postJson("/api/v1/sales/$id/cancel", ['reason' => 'Yana bir marta'])->assertStatus(422);
        $this->assertSame(10.0, $this->stockOf($p)); // ikkinchi bekor qilish qoldiqni qayta oshirmaydi
    }

    public function test_cashier_cannot_cancel_or_return(): void
    {
        $manager = $this->shop('manager', 0, ['email' => 'm@test.uz']);
        $p = $this->product('A1', 1000, 500, 10);
        $id = $this->sell($manager, [['product_id' => $p->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 2000]])->json('id');

        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);
        $this->api($cashier)->postJson("/api/v1/sales/$id/cancel", ['reason' => 'Test sababi'])->assertStatus(403);
        $this->api($cashier)->postJson("/api/v1/sales/$id/returns", ['note' => 'Nuqson', 'refund_method' => 'cash', 'items' => [['product_id' => $p->id, 'quantity' => 1]]])->assertStatus(403);
    }

    public function test_partial_returns_are_linked_and_last_return_closes_rounding_gap(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 10.00, 4.00, 10);
        $sale = $this->sell($user, [['product_id' => $p->id, 'quantity' => 3]], [['method' => 'cash', 'amount' => 29.99]], ['discount' => 0.01])
            ->assertStatus(201)->assertJsonPath('total', '29.99')->json();
        $id = $sale['id'];
        $this->assertSame(7.0, $this->stockOf($p));

        $ret = fn (float $q) => $this->api($user)->postJson("/api/v1/sales/$id/returns", [
            'note' => 'Sifatsiz', 'refund_method' => 'cash', 'items' => [['product_id' => $p->id, 'quantity' => $q]],
        ]);

        $ret(1)->assertStatus(201)->assertJsonPath('type', 'return')->assertJsonPath('total', '9.99')->assertJsonPath('parent.id', $id);
        $ret(1)->assertStatus(201)->assertJsonPath('total', '9.99');
        $ret(1)->assertStatus(201)->assertJsonPath('total', '10.01'); // jami aynan 29.99: yaxlitlash qoldig'i oxirgi qaytarishda
        $ret(1)->assertStatus(422);                                    // sotilgandan ko'p qaytarib bo'lmaydi

        $this->assertSame(10.0, $this->stockOf($p));
        $this->assertEquals(0, $this->cash());
        $this->assertEqualsWithDelta(29.99, (float) Sale::where('type', 'return')->sum('total'), 0.0001);
        $this->assertSame(3, Sale::where('parent_id', $id)->count());
        $this->assertTrue(AuditLog::where('action', 'sales.return')->where('result', 'success')->exists());
    }

    public function test_return_reduces_debt_first_and_refunds_only_the_rest(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 10000, 4000, 10);
        $customer = Customer::create(['name' => 'Ali']);
        $id = $this->sell($user, [['product_id' => $p->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 5000]], ['customer_id' => $customer->id])
            ->assertJsonPath('debt_amount', '15000.00')->json('id');

        // 1-qaytarish: 10 000 — butunlay qarzdan ayriladi, pul qaytarilmaydi (usul talab qilinmaydi).
        $this->api($user)->postJson("/api/v1/sales/$id/returns", ['note' => 'Nuqson', 'items' => [['product_id' => $p->id, 'quantity' => 1]]])
            ->assertStatus(201)->assertJsonPath('debt_amount', '10000.00')->assertJsonPath('paid_amount', '0.00');
        $this->assertEquals(5000, $this->cash());

        // 2-qaytarish: 10 000 — 5 000 qarz qoldig'i, 5 000 naqd pul qaytariladi.
        $this->api($user)->postJson("/api/v1/sales/$id/returns", ['note' => 'Nuqson', 'refund_method' => 'cash', 'items' => [['product_id' => $p->id, 'quantity' => 1]]])
            ->assertStatus(201)->assertJsonPath('debt_amount', '5000.00')->assertJsonPath('paid_amount', '5000.00');
        $this->assertEquals(0, $this->cash());
        $this->assertSame(10.0, $this->stockOf($p));
    }

    public function test_sale_with_returns_cannot_be_cancelled(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 1000, 500, 10);
        $id = $this->sell($user, [['product_id' => $p->id, 'quantity' => 2]], [['method' => 'cash', 'amount' => 2000]])->json('id');
        $this->api($user)->postJson("/api/v1/sales/$id/returns", ['note' => 'Nuqson', 'refund_method' => 'cash', 'items' => [['product_id' => $p->id, 'quantity' => 1]]])->assertStatus(201);

        $this->api($user)->postJson("/api/v1/sales/$id/cancel", ['reason' => 'Xato kiritilgan'])->assertStatus(422);
        $this->assertSame(9.0, $this->stockOf($p));
    }

    public function test_cashier_sees_only_own_sales_while_manager_sees_all(): void
    {
        $manager = $this->shop('manager', 0, ['email' => 'm@test.uz']);
        $p = $this->product('A1', 1000, 500, 50);
        $managerSale = $this->sell($manager, [['product_id' => $p->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]])->json('id');
        $this->api($manager)->postJson('/api/v1/shifts/' . $this->api($manager)->getJson('/api/v1/shifts/current')->json('shift.id') . '/close', ['actual_cash' => 1000])->assertOk();

        $cashier = $this->shop('cashier', 1000, ['email' => 'c@test.uz']);
        $own = $this->sell($cashier, [['product_id' => $p->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]])->json('id');

        $this->api($cashier)->getJson('/api/v1/sales')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $own);
        $this->api($cashier)->getJson("/api/v1/sales/$managerSale")->assertStatus(403);
        $this->api($cashier)->getJson("/api/v1/sales/$own")->assertOk();
        $this->api($manager)->getJson('/api/v1/sales')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_viewer_cannot_sell(): void
    {
        $viewer = $this->makeUser('viewer');
        $p = $this->product('A1', 1000, 500, 5);

        $this->sell($viewer, [['product_id' => $p->id, 'quantity' => 1]], [['method' => 'cash', 'amount' => 1000]])->assertStatus(403);
    }

    public function test_pos_products_search_puts_exact_barcode_first_and_returns_stock(): void
    {
        $user = $this->shop();
        $a = $this->product('COLA-1', 9000, 5000, 4, 'dona', ['barcode' => '4780001', 'name' => 'Cola 1L']);
        $this->product('COLA-Z', 9500, 5200, 0, 'dona', ['name' => 'Cola Zero']);

        $res = $this->api($user)->getJson('/api/v1/pos/products?warehouse_id=' . $this->wh->id . '&search=cola')->assertOk();
        $this->assertCount(2, $res->json());

        $exact = $this->api($user)->getJson('/api/v1/pos/products?warehouse_id=' . $this->wh->id . '&search=4780001')->assertOk();
        $this->assertSame($a->id, $exact->json('0.id'));
        $this->assertSame('4.000', $exact->json('0.stock'));
    }

    public function test_pos_context_reports_shift_warehouse_and_permissions(): void
    {
        $cashier = $this->shop('cashier', 0, ['email' => 'c@test.uz']);

        $this->api($cashier)->getJson('/api/v1/pos/context')->assertOk()
            ->assertJsonPath('default_warehouse_id', $this->wh->id)
            ->assertJsonPath('can.discount', false)
            ->assertJsonPath('can.debt', false)
            ->assertJsonPath('shift.cashbox.code', 'K-1');
    }

    public function test_customers_quick_create_and_search(): void
    {
        $cashier = $this->makeUser('cashier');

        $this->api($cashier)->postJson('/api/v1/customers', ['name' => 'Ali Valiyev', 'phone' => '+998901234567'])->assertStatus(201);
        $this->api($cashier)->getJson('/api/v1/customers?all=1&search=ali')->assertOk()->assertJsonCount(1);
        $this->api($cashier)->postJson('/api/v1/customers', [])->assertStatus(422);
    }
}
