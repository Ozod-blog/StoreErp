<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** Eslatma: opening_stock testlari docs/PHASE10C_IMPORT.md dagi patches/opening-stock.patch qo'llanganini talab qiladi. */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = "sku,name,barcode,category,unit,purchase_price,sale_price\n";

    private function csv(string $content, string $name = 'data.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload($user, string $url, array $data)
    {
        return $this->withHeaders($this->bearer($user))->post($url, $data, ['Accept' => 'application/json']);
    }

    private function dona(): Unit
    {
        return Unit::firstOrCreate(['short_name' => 'dona'], ['name' => 'Dona', 'allow_fraction' => false]);
    }

    public function test_products_import_creates_products_and_categories_and_skips_existing(): void
    {
        $admin = $this->makeUser('admin');
        $unit = $this->dona();
        Product::create(['sku' => 'A1', 'name' => 'Eski', 'unit_id' => $unit->id, 'sale_price' => 1000]);

        $csv = self::HEAD."A1,Yangi nom,,Ichimlik,dona,500,1000\nB2,Suv,4780001,Ichimlik,Dona,\"1 500,50\",\"2 000\"\nC3,Non,,,dona,,3000\n";
        $res = $this->upload($admin, '/api/v1/imports/products', ['file' => $this->csv($csv)])->assertOk()
            ->assertJsonPath('created', 2)->assertJsonPath('skipped_rows', 1);

        $this->assertSame('Eski', Product::where('sku', 'A1')->value('name'));
        $b = Product::where('sku', 'B2')->first();
        $this->assertSame('1500.50', $b->purchase_price);
        $this->assertSame('2000.00', $b->sale_price);
        $this->assertSame('0.00', Product::where('sku', 'C3')->value('purchase_price'));
        $this->assertSame(1, Category::where('name', 'Ichimlik')->count());
        $this->assertNotNull($b->category_id);
        $this->assertTrue(AuditLog::where('action', 'imports.commit')->where('result', 'success')->exists());
    }

    public function test_file_with_errors_is_rejected_entirely_and_preview_writes_nothing(): void
    {
        $admin = $this->makeUser('admin');
        $this->dona();

        $csv = self::HEAD."A1,Yaxshi,,,dona,100,200\nB2,Xato,,,kg,100,200\nC3,Narxsiz,,,dona,100,\nA1,Takror,,,dona,1,1\n";

        $p = $this->upload($admin, '/api/v1/imports/products/preview', ['file' => $this->csv($csv)])->assertOk();
        $p->assertJsonPath('valid_rows', 1)->assertJsonPath('errors_total', 3);
        $this->assertSame(0, Product::count());

        $this->upload($admin, '/api/v1/imports/products', ['file' => $this->csv($csv)])->assertStatus(422);
        $this->assertSame(0, Product::count(), 'Xato bor faylda hech narsa yozilmasligi kerak');
    }

    public function test_missing_required_column_is_reported(): void
    {
        $admin = $this->makeUser('admin');
        $this->upload($admin, '/api/v1/imports/products/preview', ['file' => $this->csv("sku,name\nA1,Nom\n")])
            ->assertOk()->assertJsonPath('errors_total', 1);
    }

    public function test_customers_import_skips_duplicate_phone(): void
    {
        $admin = $this->makeUser('admin');
        Customer::create(['name' => 'Ali', 'phone' => '+998 90 123 45 67']);

        $csv = "name,phone,address\nVali,998901234567,Toshkent\nSami,+998 91 000 00 01,\nOlim,,\n";
        $this->upload($admin, '/api/v1/imports/customers', ['file' => $this->csv($csv)])->assertOk()
            ->assertJsonPath('created', 2)->assertJsonPath('skipped_rows', 1);

        $this->assertSame(3, Customer::count());
    }

    public function test_xlsx_upload_and_template_download(): void
    {
        $admin = $this->makeUser('admin');
        $this->dona();

        $this->withHeaders($this->bearer($admin))->get('/api/v1/imports/products/template')->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $w = new Writer();
        $w->openToFile($path);
        $w->addRow(Row::fromValues(['Kod (SKU)', 'Nomi', 'O‘lchov birligi', 'Sotuv narxi']));
        $w->addRow(Row::fromValues(['X1', 'Excel mahsulot', 'dona', 12500.5]));
        $w->close();

        $file = new UploadedFile($path, 'products.xlsx', null, null, true);
        $this->upload($admin, '/api/v1/imports/products', ['file' => $file])->assertOk()->assertJsonPath('created', 1);
        $this->assertSame('12500.50', Product::where('sku', 'X1')->value('sale_price'));
    }

    public function test_opening_stock_creates_approved_document_and_sets_cost(): void
    {
        $admin = $this->makeUser('admin');
        $unit = $this->dona();
        $wh = Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        $p = Product::create(['sku' => 'A1', 'name' => 'Suv', 'unit_id' => $unit->id, 'sale_price' => 2000]);

        $res = $this->upload($admin, '/api/v1/imports/opening_stock', [
            'file' => $this->csv("sku,quantity,cost\nA1,10,1500\n"), 'warehouse_id' => $wh->id,
        ])->assertOk()->assertJsonPath('created', 1);

        $this->assertStringStartsWith('BQ-', $res->json('document.number'));
        $this->assertSame('10.000', WarehouseStock::where('warehouse_id', $wh->id)->where('product_id', $p->id)->value('quantity'));
        $this->assertSame('1500.0000', $p->fresh()->avg_cost);
        $doc = StockDocument::where('type', 'opening')->first();
        $this->assertSame('approved', $doc->status);
        $this->assertTrue(StockMovement::where('stock_document_id', $doc->id)->where('type', 'opening')->exists());
    }

    public function test_opening_stock_rejects_unknown_product_and_fraction_for_integer_unit(): void
    {
        $admin = $this->makeUser('admin');
        $unit = $this->dona();
        $wh = Warehouse::create(['code' => 'W-1', 'name' => 'Ombor']);
        Product::create(['sku' => 'A1', 'name' => 'Suv', 'unit_id' => $unit->id, 'sale_price' => 2000]);

        $r = $this->upload($admin, '/api/v1/imports/opening_stock/preview', [
            'file' => $this->csv("sku,quantity,cost\nA1,1.5,100\nZZ,2,100\n"), 'warehouse_id' => $wh->id,
        ])->assertOk();
        $r->assertJsonPath('errors_total', 2)->assertJsonPath('valid_rows', 0);
        $this->assertSame(0, StockDocument::count());
    }

    public function test_cashier_cannot_import(): void
    {
        $cashier = $this->makeUser('cashier', ['email' => 'c@test.uz']);
        $this->upload($cashier, '/api/v1/imports/products', ['file' => $this->csv(self::HEAD)])->assertStatus(403);
        $this->withHeaders($this->bearer($cashier))->get('/api/v1/imports/products/template')->assertStatus(403);
    }

    public function test_unknown_type_and_bad_extension(): void
    {
        $admin = $this->makeUser('admin');
        $this->upload($admin, '/api/v1/imports/nope', ['file' => $this->csv('a')])->assertStatus(404);
        $this->upload($admin, '/api/v1/imports/products', ['file' => UploadedFile::fake()->createWithContent('x.exe', 'MZ')])->assertStatus(422);
    }
}
