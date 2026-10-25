<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cashbox;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyDebtsTest extends TestCase
{
    use RefreshDatabase;

    private ?Warehouse $wh = null;

    private ?Cashbox $box = null;

    private function api($user)
    {
        return $this->withHeaders($this->bearer($user));
    }

    private function shop(string $role = 'manager', float $opening = 0, array $attrs = [])
    {
        $user = $this->makeUser($role, $attrs);
        $this->wh ??= Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        $this->box ??= Cashbox::create(['code' => 'K-1', 'name' => 'Kassa 1']);
        $this->api($user)->postJson('/api/v1/shifts/open', ['cashbox_id' => $this->box->id, 'opening_cash' => $opening])->assertStatus(201);

        return $user;
    }

    private function product(float $price = 10000, float $qty = 100): Product
    {
        $u = Unit::firstOrCreate(['short_name' => 'dona'], ['name' => 'Dona', 'allow_fraction' => false]);
        $p = Product::create(['sku' => 'P' . Product::count(), 'name' => 'Mahsulot', 'unit_id' => $u->id, 'sale_price' => $price]);
        $p->forceFill(['avg_cost' => $price / 2])->save();
        WarehouseStock::create(['warehouse_id' => $this->wh->id, 'product_id' => $p->id, 'quantity' => $qty]);

        return $p;
    }

    /** Mijozga to'liq qarzga sotuv (5 dona x 10 000 = 50 000). */
    private function debtSale($user, Customer $c, int $qty = 5, array $payments = []): int
    {
        $p = $this->product();

        return $this->api($user)->postJson('/api/v1/sales', [
            'customer_id' => $c->id,
            'items' => [['product_id' => $p->id, 'quantity' => $qty]],
            'payments' => $payments,
        ])->assertStatus(201)->json('id');
    }

    private function cash(): float
    {
        return app(CashService::class)->balanceUnits($this->box->id) / 100;
    }

    private function debtOf(Customer $c, $user): string
    {
        return $this->api($user)->getJson("/api/v1/customers/{$c->id}")->assertOk()->json('summary.debt');
    }

    private function pay($user, Customer $c, float $amount, string $method = 'cash', array $extra = [])
    {
        return $this->api($user)->postJson("/api/v1/customers/{$c->id}/payments", $extra + ['amount' => $amount, 'method' => $method]);
    }

    public function test_customer_debt_comes_from_credit_sales_and_list_is_compatible_with_pos(): void
    {
        $user = $this->shop();
        $c = Customer::create(['name' => 'Ali']);
        Customer::create(['name' => 'Vali']);
        $this->debtSale($user, $c, 5, [['method' => 'cash', 'amount' => 10000]]); // 50 000 jami, 10 000 to'langan

        $this->assertSame('40000.00', $this->debtOf($c, $user));
        $this->api($user)->getJson('/api/v1/customers?has_debt=1')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.name', 'Ali');
        $this->api($user)->getJson('/api/v1/customers/summary')->assertOk()
            ->assertJsonPath('total_debt', '40000.00')->assertJsonPath('debtors_count', 1);

        // POS tanlovi (6-bosqich) o'zgarmagan: faqat id, name, phone
        $all = $this->api($user)->getJson('/api/v1/customers?all=1')->assertOk()->json();
        $this->assertSame(['id', 'name', 'phone'], array_keys($all[0]));
    }

    public function test_partial_cash_payments_reduce_debt_and_write_cash_ledger(): void
    {
        $user = $this->shop('manager', 0);
        $c = Customer::create(['name' => 'Ali']);
        $this->debtSale($user, $c);

        $this->pay($user, $c, 30000)->assertStatus(201)->assertJsonPath('status', 'approved');
        $this->assertSame('20000.00', $this->debtOf($c, $user));
        $this->assertEquals(30000, $this->cash());
        $tx = CashTransaction::where('category', 'debt_payment')->firstOrFail();
        $this->assertSame('in', $tx->direction);
        $this->assertSame('customer_payment', $tx->source_type);

        $this->pay($user, $c, 20001)->assertStatus(422); // qarzdan oshmaydi
        $this->pay($user, $c, 20000)->assertStatus(201);
        $this->assertSame('0.00', $this->debtOf($c, $user));
        $this->assertEquals(50000, $this->cash());
        $this->pay($user, $c, 1)->assertStatus(422);     // qarz yo'q
        $this->assertTrue(AuditLog::where('action', 'customer_payments.create')->where('result', 'success')->exists());
    }

    public function test_non_cash_payment_needs_reference_and_does_not_touch_cash(): void
    {
        $user = $this->shop();
        $c = Customer::create(['name' => 'Ali']);
        $this->debtSale($user, $c);

        $this->pay($user, $c, 10000, 'click')->assertStatus(422);
        $this->pay($user, $c, 10000, 'click', ['reference' => 'TX-1'])->assertStatus(201)->assertJsonPath('is_online_verified', false);
        $this->pay($user, $c, 5000, 'card')->assertStatus(201);
        $this->assertSame('35000.00', $this->debtOf($c, $user));
        $this->assertEquals(0, $this->cash());
    }

    public function test_cash_payment_requires_open_shift(): void
    {
        $manager = $this->shop();
        $c = Customer::create(['name' => 'Ali']);
        $this->debtSale($manager, $c);

        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']); // smena ochmagan
        $this->pay($cashier, $c, 1000)->assertStatus(422);
        $this->pay($cashier, $c, 1000, 'card')->assertStatus(201);       // naqd bo'lmasa smena shart emas
        $this->assertSame('49000.00', $this->debtOf($c, $manager));
    }

    public function test_resubmitted_payment_is_not_applied_twice(): void
    {
        $user = $this->shop();
        $c = Customer::create(['name' => 'Ali']);
        $this->debtSale($user, $c);

        $this->pay($user, $c, 10000, 'cash', ['client_token' => 'tok-1'])->assertStatus(201);
        $this->pay($user, $c, 10000, 'cash', ['client_token' => 'tok-1'])->assertOk();
        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame('40000.00', $this->debtOf($c, $user));
        $this->assertEquals(10000, $this->cash());
    }

    public function test_cancel_payment_restores_debt_and_reverses_cash_once(): void
    {
        $user = $this->shop();
        $c = Customer::create(['name' => 'Ali']);
        $this->debtSale($user, $c);
        $id = $this->pay($user, $c, 20000)->json('id');

        $this->api($user)->postJson("/api/v1/customer-payments/$id/cancel", [])->assertStatus(422);            // sabab majburiy
        $this->api($user)->postJson("/api/v1/customer-payments/$id/cancel", ['reason' => 'Xato kiritilgan'])
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame('50000.00', $this->debtOf($c, $user));
        $this->assertEquals(0, $this->cash());
        $this->assertTrue(CashTransaction::where('category', 'debt_payment_cancel')->where('direction', 'out')->exists());

        $this->api($user)->postJson("/api/v1/customer-payments/$id/cancel", ['reason' => 'Yana'])->assertStatus(422);
        $this->assertSame(1, CashTransaction::where('category', 'debt_payment_cancel')->count());
    }

    public function test_sale_return_reduces_debt_and_statement_has_running_balance(): void
    {
        $user = $this->shop();
        $c = Customer::create(['name' => 'Ali']);
        $saleId = $this->debtSale($user, $c);                       // +50 000
        $this->pay($user, $c, 20000)->assertStatus(201);            // −20 000
        $p = Product::first();
        $this->api($user)->postJson("/api/v1/sales/$saleId/returns", ['note' => 'Nuqson', 'items' => [['product_id' => $p->id, 'quantity' => 1]]])
            ->assertStatus(201);                                    // −10 000 (qarzdan)

        $this->assertSame('20000.00', $this->debtOf($c, $user));
        $st = $this->api($user)->getJson("/api/v1/customers/{$c->id}/statement")->assertOk();
        $this->assertSame('0.00', $st->json('opening_balance'));
        $this->assertSame('20000.00', $st->json('closing_balance'));
        // Bir soniya ichidagi hodisalar tartibi qat'iy emas; boshlanishi (savdo) va yakuniy qoldiq aniq.
        $balances = array_column($st->json('rows'), 'balance');
        $this->assertCount(3, $balances);
        $this->assertSame('50000.00', $balances[0]);
        $this->assertSame('20000.00', $balances[2]);
        $this->assertEqualsCanonicalizing(['sale', 'payment', 'return'], array_column($st->json('rows'), 'kind'));

        // Davr filtri: boshlang'ich qoldiq to'g'ri hisoblanadi
        $future = $this->api($user)->getJson("/api/v1/customers/{$c->id}/statement?from=2999-01-01")->assertOk();
        $this->assertSame('20000.00', $future->json('opening_balance'));
        $this->assertSame([], $future->json('rows'));
    }

    public function test_cancelled_sale_is_not_counted_and_customer_with_debt_cannot_be_deactivated(): void
    {
        $user = $this->shop('admin');
        $c = Customer::create(['name' => 'Ali']);
        $saleId = $this->debtSale($user, $c);
        $this->api($user)->deleteJson("/api/v1/customers/{$c->id}")->assertStatus(422);
        $this->assertTrue($c->fresh()->is_active);

        $this->api($user)->postJson("/api/v1/sales/$saleId/cancel", ['reason' => 'Xato sotuv'])->assertOk();
        $this->assertSame('0.00', $this->debtOf($c, $user));
        $this->api($user)->deleteJson("/api/v1/customers/{$c->id}")->assertOk();
        $this->assertFalse($c->fresh()->is_active);
    }

    private function supplierWithDebt(): Supplier
    {
        $s = Supplier::create(['name' => 'Yetkazuvchi']);
        $wh = $this->wh ?? Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        $purchase = StockDocument::create([
            'number' => 'XR-T1',
            'type' => 'purchase',
            'status' => 'approved',
            'warehouse_id' => $wh->id,
            'supplier_id' => $s->id,
            'doc_date' => '2026-10-12',
            'total' => 100000,
            'paid_amount' => 40000,
            'payment_method' => 'cash',
            'approved_at' => now(),
        ]);
        StockDocument::create([
            'number' => 'QY-T1',
            'type' => 'purchase_return',
            'status' => 'approved',
            'warehouse_id' => $wh->id,
            'supplier_id' => $s->id,
            'parent_id' => $purchase->id,
            'doc_date' => '2026-10-13',
            'total' => 10000,
            'approved_at' => now(),
        ]);
        // Qoralama va bekor qilingan hujjatlar qarzga ta'sir qilmaydi
        StockDocument::create(['number' => 'XR-T2', 'type' => 'purchase', 'status' => 'draft', 'warehouse_id' => $wh->id, 'supplier_id' => $s->id, 'doc_date' => '2026-10-14', 'total' => 999999]);
        StockDocument::create(['number' => 'XR-T3', 'type' => 'purchase', 'status' => 'cancelled', 'warehouse_id' => $wh->id, 'supplier_id' => $s->id, 'doc_date' => '2026-10-14', 'total' => 888888]);

        return $s;
    }

    public function test_supplier_debt_formula_and_partial_payments(): void
    {
        $user = $this->shop('manager', 100000);
        $s = $this->supplierWithDebt();                              // (100 000 − 40 000) − 10 000 = 50 000

        $this->api($user)->getJson("/api/v1/suppliers/{$s->id}")->assertOk()
            ->assertJsonPath('summary.debt', '50000.00')->assertJsonPath('summary.net_total', '90000.00')->assertJsonPath('summary.paid_total', '40000.00');

        $this->api($user)->postJson("/api/v1/suppliers/{$s->id}/payments", ['amount' => 20000, 'method' => 'cash'])
            ->assertStatus(201);
        $this->assertEquals(80000, $this->cash());
        $this->assertTrue(CashTransaction::where('category', 'supplier_payment')->where('direction', 'out')->exists());

        $this->api($user)->postJson("/api/v1/suppliers/{$s->id}/payments", ['amount' => 30001, 'method' => 'bank'])->assertStatus(422);
        $this->api($user)->postJson("/api/v1/suppliers/{$s->id}/payments", ['amount' => 30000, 'method' => 'bank', 'reference' => 'PP-77'])->assertStatus(201);
        $this->assertEquals(80000, $this->cash());                    // bank kassaga ta'sir qilmaydi
        $this->assertSame('0.00', $this->api($user)->getJson("/api/v1/suppliers/{$s->id}")->json('summary.debt'));

        $st = $this->api($user)->getJson("/api/v1/suppliers/{$s->id}/statement")->assertOk();
        $this->assertSame(['60000.00', '50000.00', '30000.00', '0.00'], array_column($st->json('rows'), 'balance'));
    }

    public function test_supplier_cash_payment_fails_when_cashbox_has_not_enough_money(): void
    {
        $user = $this->shop('manager', 5000);
        $s = $this->supplierWithDebt();

        $this->api($user)->postJson("/api/v1/suppliers/{$s->id}/payments", ['amount' => 20000, 'method' => 'cash'])->assertStatus(422);
        $this->assertSame(0, SupplierPayment::count());
        $this->assertSame('50000.00', $this->api($user)->getJson("/api/v1/suppliers/{$s->id}")->json('summary.debt'));
    }

    public function test_cancel_supplier_payment_returns_cash_and_restores_debt(): void
    {
        $user = $this->shop('manager', 100000);
        $s = $this->supplierWithDebt();
        $id = $this->api($user)->postJson("/api/v1/suppliers/{$s->id}/payments", ['amount' => 20000, 'method' => 'cash'])->json('id');

        $this->api($user)->postJson("/api/v1/supplier-payments/$id/cancel", ['reason' => 'Noto‘g‘ri to‘lov'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertEquals(100000, $this->cash());
        $this->assertSame('50000.00', $this->api($user)->getJson("/api/v1/suppliers/{$s->id}")->json('summary.debt'));
        $this->api($user)->postJson("/api/v1/supplier-payments/$id/cancel", ['reason' => 'Yana'])->assertStatus(422);
    }

    public function test_permissions_are_enforced_on_backend(): void
    {
        $manager = $this->shop();
        $c = Customer::create(['name' => 'Ali']);
        $this->debtSale($manager, $c);
        $s = $this->supplierWithDebt();
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);

        // Kassir: mijoz ko'ra oladi va qarz to'lovini qabul qila oladi, lekin bekor qila olmaydi / tahrirlay olmaydi
        $this->api($cashier)->getJson("/api/v1/customers/{$c->id}/statement")->assertOk();
        $id = $this->pay($cashier, $c, 1000, 'card')->assertStatus(201)->json('id');
        $this->api($cashier)->postJson("/api/v1/customer-payments/$id/cancel", ['reason' => 'Test sababi'])->assertStatus(403);
        $this->api($cashier)->patchJson("/api/v1/customers/{$c->id}", ['name' => 'Boshqa'])->assertStatus(403);

        // Yetkazib beruvchilar moduli kassirga yopiq
        $this->api($cashier)->getJson("/api/v1/suppliers/{$s->id}")->assertStatus(403);
        $this->api($cashier)->postJson("/api/v1/suppliers/{$s->id}/payments", ['amount' => 1000, 'method' => 'bank'])->assertStatus(403);

        // Omborchi yetkazib beruvchini ko'radi, lekin to'lay olmaydi
        $keeper = $this->makeUser('warehouse', ['email' => 'k@test.uz']);
        $this->api($keeper)->getJson("/api/v1/suppliers/{$s->id}")->assertOk();
        $this->api($keeper)->postJson("/api/v1/suppliers/{$s->id}/payments", ['amount' => 1000, 'method' => 'bank'])->assertStatus(403);
    }
}