<?php

namespace Tests\Feature;

use App\Models\Cashbox;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportCenterTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    private Cashbox $box;

    private function api($user)
    {
        return $this->withHeaders($this->bearer($user));
    }

    private function shop(string $role = 'manager', array $attrs = []): \App\Models\User
    {
        $user = $this->makeUser($role, $attrs);
        $this->wh ??= Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        $this->box ??= Cashbox::create(['code' => 'K-1', 'name' => 'Kassa 1']);
        $this->api($user)->postJson('/api/v1/shifts/open', ['cashbox_id' => $this->box->id, 'opening_cash' => 0])->assertStatus(201);

        return $user;
    }

    private function product(string $sku, float $price, float $cost, float $qty): Product
    {
        $u = Unit::firstOrCreate(['short_name' => 'dona'], ['name' => 'Dona', 'allow_fraction' => false]);
        $p = Product::create(['sku' => $sku, 'name' => "Mahsulot $sku", 'unit_id' => $u->id, 'sale_price' => $price]);
        $p->forceFill(['avg_cost' => $cost])->save();
        WarehouseStock::create(['warehouse_id' => $this->wh->id, 'product_id' => $p->id, 'quantity' => $qty]);

        return $p;
    }

    private function sellTwo($user, Product $p): int
    {
        return $this->api($user)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 24000]],
        ])->assertStatus(201)->json('id');
    }

    public function test_profit_report_uses_frozen_cost_and_subtracts_returns(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 12000, 7000, 10);
        $saleId = $this->sellTwo($user, $p);

        $r = $this->api($user)->getJson('/api/v1/reports/center/profit_by_product')->assertOk();
        $r->assertJsonPath('rows.0.revenue', '24000.00')->assertJsonPath('rows.0.cost', '14000.00')
            ->assertJsonPath('rows.0.profit', '10000.00')->assertJsonPath('rows.0.margin', '41.67')
            ->assertJsonPath('totals.profit', '10000.00');

        // Tannarx keyinchalik o'zgarsa ham, o'tgan sotuv foydasi o'zgarmaydi.
        $p->forceFill(['avg_cost' => 9000])->save();

        $this->api($user)->postJson("/api/v1/sales/$saleId/returns", [
            'note' => 'Nuqson', 'refund_method' => 'cash', 'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->api($user)->getJson('/api/v1/reports/center/profit_by_product')->assertOk()
            ->assertJsonPath('rows.0.quantity', '1.000')->assertJsonPath('rows.0.revenue', '12000.00')
            ->assertJsonPath('rows.0.cost', '7000.00')->assertJsonPath('rows.0.profit', '5000.00');
    }

    public function test_daily_employee_and_stock_reports(): void
    {
        $user = $this->shop();
        $p = $this->product('A1', 12000, 7000, 10);
        $this->sellTwo($user, $p);

        $this->api($user)->getJson('/api/v1/reports/center/sales_daily')->assertOk()
            ->assertJsonPath('rows.0.sales_count', 1)->assertJsonPath('rows.0.net_sales', '24000.00');
        $this->api($user)->getJson('/api/v1/reports/center/sales_by_employee')->assertOk()
            ->assertJsonPath('rows.0.employee', $user->name)->assertJsonPath('rows.0.profit', '10000.00');
        $this->api($user)->getJson('/api/v1/reports/center/stock')->assertOk()
            ->assertJsonPath('rows.0.quantity', '8.000')->assertJsonPath('rows.0.cost_value', '56000.00');
    }

    public function test_employee_filter_limits_rows(): void
    {
        $user = $this->shop();
        $other = $this->makeUser('viewer', ['email' => 'other@test.uz']);
        $this->sellTwo($user, $this->product('A1', 12000, 7000, 10));

        $this->api($user)->getJson('/api/v1/reports/center/sales_by_product?user_id='.$user->id)->assertOk()->assertJsonCount(1, 'rows');
        $this->api($user)->getJson('/api/v1/reports/center/sales_by_product?user_id='.$other->id)->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_permission_and_unknown_report(): void
    {
        $manager = $this->shop();
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);

        $this->api($cashier)->getJson('/api/v1/reports/center/stock')->assertStatus(403);
        $this->api($cashier)->get('/api/v1/reports/center/stock/export?format=xlsx')->assertStatus(403);
        $this->api($manager)->getJson('/api/v1/reports/center/nope')->assertStatus(404);
        $this->api($manager)->getJson('/api/v1/reports/center/stock/export?format=csv')->assertStatus(422);
    }

    public function test_export_xlsx_and_pdf_are_audited(): void
    {
        $user = $this->shop();
        $this->sellTwo($user, $this->product('A1', 12000, 7000, 10));

        $x = $this->api($user)->get('/api/v1/reports/center/profit_by_product/export?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $x->headers->get('content-type'));

        $p = $this->api($user)->get('/api/v1/reports/center/profit_by_product/export?format=pdf')->assertOk();
        $this->assertStringContainsString('pdf', (string) $p->headers->get('content-type'));

        $this->assertSame(2, \App\Models\AuditLog::where('action', 'reports.export')->where('result', 'success')->count());
    }
}
