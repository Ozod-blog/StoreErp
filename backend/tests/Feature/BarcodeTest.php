<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Barcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeTest extends TestCase
{
    use RefreshDatabase;

    private function api($user)
    {
        return $this->withHeaders($this->bearer($user));
    }

    private function product(string $sku, array $extra = []): Product
    {
        $u = Unit::firstOrCreate(['short_name' => 'dona'], ['name' => 'Dona', 'allow_fraction' => false]);

        return Product::create($extra + ['sku' => $sku, 'name' => "Mahsulot $sku", 'unit_id' => $u->id, 'sale_price' => 1000]);
    }

    public function test_ean13_check_digit_matches_known_codes(): void
    {
        // Ommaviy ma'lum misollar: 4006381333931 (Stabilo), 5901234123457 (GS1 namunasi)
        $this->assertSame(1, Barcode::ean13Check('400638133393'));
        $this->assertSame(7, Barcode::ean13Check('590123412345'));
        $this->assertTrue(Barcode::isValidEan13('4006381333931'));
        $this->assertTrue(Barcode::isValidEan13('5901234123457'));
        $this->assertFalse(Barcode::isValidEan13('4006381333932'));
        $this->assertFalse(Barcode::isValidEan13('12345'));
    }

    public function test_internal_code_is_valid_ean13_with_internal_prefix(): void
    {
        $code = Barcode::internalFromId(42);
        $this->assertSame(13, strlen($code));
        $this->assertStringStartsWith('2', $code);
        $this->assertTrue(Barcode::isValidEan13($code));
        $this->assertTrue(Barcode::isValidEan13(Barcode::internalRandom()));
    }

    public function test_generate_barcode_fills_empty_and_is_audited(): void
    {
        $admin = $this->makeUser('admin');
        $p = $this->product('A1');

        $res = $this->api($admin)->postJson("/api/v1/products/{$p->id}/barcode")->assertOk();
        $code = $res->json('barcode');
        $this->assertTrue(Barcode::isValidEan13($code));
        $this->assertSame($code, $p->fresh()->barcode);
        $this->assertTrue(AuditLog::where('action', 'products.update')->exists());
    }

    public function test_generate_barcode_never_overwrites_existing(): void
    {
        $admin = $this->makeUser('admin');
        $p = $this->product('A1', ['barcode' => '4006381333931']);

        $this->api($admin)->postJson("/api/v1/products/{$p->id}/barcode")->assertStatus(422);
        $this->assertSame('4006381333931', $p->fresh()->barcode);
    }

    public function test_generate_barcode_avoids_collision_with_manual_code(): void
    {
        $admin = $this->makeUser('admin');
        $p = $this->product('A1');
        // Boshqa mahsulotga qo'lda aynan shu ID-asosli kod yozib qo'yilgan
        $this->product('A2', ['barcode' => Barcode::internalFromId($p->id)]);

        $code = $this->api($admin)->postJson("/api/v1/products/{$p->id}/barcode")->assertOk()->json('barcode');
        $this->assertNotSame(Barcode::internalFromId($p->id), $code);
        $this->assertTrue(Barcode::isValidEan13($code));
    }

    public function test_generate_barcode_requires_update_permission(): void
    {
        $cashier = $this->makeUser('cashier');
        $p = $this->product('A1');

        $this->api($cashier)->postJson("/api/v1/products/{$p->id}/barcode")->assertStatus(403);
        $this->assertNull($p->fresh()->barcode);
    }

    public function test_scan_finds_exact_barcode_or_sku_only(): void
    {
        $cashier = $this->makeUser('cashier');
        $wh = Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        $p = $this->product('SUT-1', ['barcode' => '4006381333931']);
        WarehouseStock::create(['warehouse_id' => $wh->id, 'product_id' => $p->id, 'quantity' => 5]);

        $q = ['warehouse_id' => $wh->id];
        $this->api($cashier)->getJson('/api/v1/pos/scan?'.http_build_query($q + ['code' => '4006381333931']))
            ->assertOk()->assertJsonPath('id', $p->id)->assertJsonPath('stock', '5.000');
        $this->api($cashier)->getJson('/api/v1/pos/scan?'.http_build_query($q + ['code' => 'sut-1']))
            ->assertOk()->assertJsonPath('id', $p->id);
        // Qisman moslik topilmaydi (qidiruvdan farqli)
        $this->api($cashier)->getJson('/api/v1/pos/scan?'.http_build_query($q + ['code' => '400638']))->assertStatus(404);
        $this->api($cashier)->getJson('/api/v1/pos/scan?'.http_build_query($q + ['code' => 'SUT']))->assertStatus(404);
    }

    public function test_scan_prefers_barcode_owner_over_sku_collision_and_skips_inactive(): void
    {
        $cashier = $this->makeUser('cashier');
        $wh = Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        $this->product('123456', ['name' => 'SKU egasi']);
        $byBarcode = $this->product('B-1', ['barcode' => '123456', 'name' => 'Shtrix egasi']);
        $this->product('OFF-1', ['barcode' => '999', 'is_active' => false]);

        $this->api($cashier)->getJson('/api/v1/pos/scan?'.http_build_query(['warehouse_id' => $wh->id, 'code' => '123456']))
            ->assertOk()->assertJsonPath('id', $byBarcode->id);
        $this->api($cashier)->getJson('/api/v1/pos/scan?'.http_build_query(['warehouse_id' => $wh->id, 'code' => '999']))->assertStatus(404);
    }

    public function test_scan_requires_sales_permission(): void
    {
        $warehouseRole = $this->makeUser('warehouse');
        $wh = Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);

        $this->api($warehouseRole)->getJson('/api/v1/pos/scan?'.http_build_query(['warehouse_id' => $wh->id, 'code' => 'x']))->assertStatus(403);
    }
}
