<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private function unit(string $short = 'dona', bool $fraction = false): Unit
    {
        return Unit::firstOrCreate(['short_name' => $short], ['name' => ucfirst($short), 'allow_fraction' => $fraction]);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'sku' => 'SKU-001',
            'name' => 'Test mahsulot',
            'unit_id' => $this->unit()->id,
            'purchase_price' => 1000,
            'sale_price' => 1500,
        ];
    }

    private function api($user)
    {
        return $this->withHeaders($this->bearer($user));
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->getJson('/api/v1/warehouses')->assertStatus(401);
    }

    public function test_cashier_can_view_but_not_create_products(): void
    {
        $cashier = $this->makeUser('cashier');

        $this->api($cashier)->getJson('/api/v1/products')->assertOk();
        $this->api($cashier)->postJson('/api/v1/products', $this->payload())->assertStatus(403);
        $this->api($cashier)->getJson('/api/v1/warehouses')->assertStatus(403);
    }

    public function test_admin_creates_product_and_it_is_audited_without_stock(): void
    {
        $admin = $this->makeUser('admin');

        $res = $this->api($admin)->postJson('/api/v1/products', $this->payload(['quantity' => 999]));
        $res->assertStatus(201)->assertJsonPath('sku', 'SKU-001');

        $product = Product::first();
        $this->assertSame(0, WarehouseStock::where('product_id', $product->id)->count(), 'Mahsulot formasi qoldiq yaratmasligi kerak');
        $this->assertTrue(AuditLog::where('action', 'products.create')->where('auditable_id', $product->id)->exists());
    }

    public function test_sku_must_be_unique_but_empty_barcodes_do_not_clash(): void
    {
        $admin = $this->makeUser('admin');

        $this->api($admin)->postJson('/api/v1/products', $this->payload(['barcode' => '']))->assertStatus(201);
        $this->api($admin)->postJson('/api/v1/products', $this->payload(['sku' => 'SKU-002', 'name' => 'Ikkinchi', 'barcode' => '']))->assertStatus(201);
        $this->api($admin)->postJson('/api/v1/products', $this->payload())->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    public function test_fractional_min_stock_depends_on_unit(): void
    {
        $admin = $this->makeUser('admin');

        $this->api($admin)->postJson('/api/v1/products', $this->payload(['min_stock' => 2.5]))
            ->assertStatus(422)->assertJsonValidationErrors('min_stock');

        $kg = $this->unit('kg', true);
        $this->api($admin)->postJson('/api/v1/products', $this->payload(['sku' => 'KG-1', 'unit_id' => $kg->id, 'min_stock' => 2.5]))
            ->assertStatus(201);
    }

    public function test_product_is_archived_not_deleted_and_can_be_restored(): void
    {
        $admin = $this->makeUser('admin');
        $id = $this->api($admin)->postJson('/api/v1/products', $this->payload())->json('id');

        $this->api($admin)->deleteJson("/api/v1/products/$id")->assertOk();
        $this->assertSoftDeleted('products', ['id' => $id]);
        $this->assertSame(0, $this->api($admin)->getJson('/api/v1/products')->json('total'));
        $this->assertSame(1, $this->api($admin)->getJson('/api/v1/products?archived=1')->json('total'));

        $this->api($admin)->postJson("/api/v1/products/$id/restore")->assertOk();
        $this->assertSame(1, $this->api($admin)->getJson('/api/v1/products')->json('total'));
    }

    public function test_warehouse_role_cannot_archive_products(): void
    {
        $admin = $this->makeUser('admin');
        $id = $this->api($admin)->postJson('/api/v1/products', $this->payload())->json('id');

        $keeper = $this->makeUser('warehouse', ['email' => 'keeper@test.local']);
        $this->api($keeper)->deleteJson("/api/v1/products/$id")->assertStatus(403);
    }

    public function test_low_stock_filter_uses_total_across_warehouses(): void
    {
        $admin = $this->makeUser('admin');
        $id = $this->api($admin)->postJson('/api/v1/products', $this->payload(['min_stock' => 10]))->json('id');
        $w1 = Warehouse::create(['code' => 'W1', 'name' => 'Ombor 1']);
        $w2 = Warehouse::create(['code' => 'W2', 'name' => 'Ombor 2']);

        WarehouseStock::create(['warehouse_id' => $w1->id, 'product_id' => $id, 'quantity' => 4]);
        $this->assertSame(1, $this->api($admin)->getJson('/api/v1/products?low_stock=1')->json('total'));

        WarehouseStock::create(['warehouse_id' => $w2->id, 'product_id' => $id, 'quantity' => 7]); // jami 11 >= 10
        $this->assertSame(0, $this->api($admin)->getJson('/api/v1/products?low_stock=1')->json('total'));
    }

    public function test_category_is_archived_and_hidden_from_options(): void
    {
        $admin = $this->makeUser('admin');
        $id = $this->api($admin)->postJson('/api/v1/categories', ['name' => 'Ichimliklar'])->json('id');

        $this->assertCount(1, $this->api($admin)->getJson('/api/v1/categories?all=1')->json());
        $this->api($admin)->deleteJson("/api/v1/categories/$id")->assertOk();
        $this->assertCount(0, $this->api($admin)->getJson('/api/v1/categories?all=1')->json());
        $this->assertFalse(Category::find($id)->is_active);
    }

    public function test_used_unit_cannot_be_deleted(): void
    {
        $admin = $this->makeUser('admin');
        $this->api($admin)->postJson('/api/v1/products', $this->payload())->assertStatus(201);
        $unitId = Product::first()->unit_id;

        $this->api($admin)->deleteJson("/api/v1/units/$unitId")->assertStatus(422);
        $this->assertNotNull(Unit::find($unitId));
    }

    public function test_warehouse_with_stock_cannot_be_deactivated(): void
    {
        $admin = $this->makeUser('admin');
        $productId = $this->api($admin)->postJson('/api/v1/products', $this->payload())->json('id');
        $whId = $this->api($admin)->postJson('/api/v1/warehouses', ['code' => 'WH-9', 'name' => 'Sinov ombori'])->json('id');
        WarehouseStock::create(['warehouse_id' => $whId, 'product_id' => $productId, 'quantity' => 3]);

        $this->api($admin)->deleteJson("/api/v1/warehouses/$whId")->assertStatus(422);
        $this->assertTrue(Warehouse::find($whId)->is_active);

        WarehouseStock::where('warehouse_id', $whId)->update(['quantity' => 0]);
        $this->api($admin)->deleteJson("/api/v1/warehouses/$whId")->assertOk();
        $this->assertFalse(Warehouse::find($whId)->is_active);
    }

    public function test_unit_change_blocked_when_product_has_stock(): void
    {
        $admin = $this->makeUser('admin');
        $productId = $this->api($admin)->postJson('/api/v1/products', $this->payload())->json('id');
        $wh = Warehouse::create(['code' => 'W1', 'name' => 'Ombor']);
        WarehouseStock::create(['warehouse_id' => $wh->id, 'product_id' => $productId, 'quantity' => 5]);
        $kg = $this->unit('kg', true);

        $this->api($admin)->putJson("/api/v1/products/$productId", $this->payload(['unit_id' => $kg->id]))->assertStatus(422);
    }
}
