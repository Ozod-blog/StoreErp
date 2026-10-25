<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Dec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel/CSV import: mahsulotlar, mijozlar, boshlang'ich qoldiqlar.
 *
 * Qoidalar:
 *  - Avval tekshiruv (preview), keyin import. Import ham serverda qayta to'liq tekshiriladi.
 *  - Faylda bitta xato bo'lsa HAMMASI rad etiladi (qisman import yo'q): hech narsa yozilmaydi.
 *  - Mavjud mahsulot (kod bo'yicha) va mavjud mijoz (telefon bo'yicha) o'tkazib yuboriladi, o'zgartirilmaydi.
 *  - Boshlang'ich qoldiq mahsulot formasidan emas, `opening` turidagi ombor hujjati orqali kiradi
 *    (StockService: qoldiq, o'rtacha tannarx va audit bitta joyda).
 */
class ImportService
{
    public const MAX_ROWS = 2000;

    private const MONEY_MAX = 999999999999.99;

    public function __construct(private StockService $stock) {}

    /** type => [label, permissions, columns: key => [label, aliases, required]] */
    public function types(): array
    {
        return [
            'products' => [
                'label' => 'Mahsulotlar',
                'permissions' => ['products.create'],
                'columns' => [
                    'sku' => ['Kod (SKU)', ['sku', 'kod', 'ichkikod'], true],
                    'name' => ['Nomi', ['name', 'nom', 'mahsulot'], true],
                    'barcode' => ['Shtrix-kod', ['barcode', 'shtrixkod', 'shtrix'], false],
                    'category' => ['Kategoriya', ['category', 'toifa'], false],
                    'unit' => ['O‘lchov birligi', ['unit', 'birlik'], true],
                    'purchase_price' => ['Xarid narxi', ['purchaseprice', 'xaridnarxi'], false],
                    'sale_price' => ['Sotuv narxi', ['saleprice', 'sotuvnarxi', 'narx'], true],
                    'wholesale_price' => ['Ulgurji narx', ['wholesaleprice', 'ulgurjinarxi'], false],
                    'min_stock' => ['Minimal qoldiq', ['minstock', 'minimalqoldiq'], false],
                    'description' => ['Tavsif', ['description', 'izoh'], false],
                ],
                'hint' => 'Mavjud kod (SKU) bo‘yicha mahsulotlar o‘tkazib yuboriladi. Birlik nomi tizimdagi birlikka mos bo‘lishi kerak. Yo‘q kategoriya avtomatik yaratiladi. Xarid narxi bo‘sh bo‘lsa 0 olinadi.',
            ],
            'customers' => [
                'label' => 'Mijozlar',
                'permissions' => ['customers.create'],
                'columns' => [
                    'name' => ['Ism / kompaniya', ['name', 'ism', 'nomi', 'kompaniya', 'mijoz'], true],
                    'phone' => ['Telefon', ['phone', 'tel'], false],
                    'address' => ['Manzil', ['address'], false],
                    'note' => ['Izoh', ['note', 'comment'], false],
                ],
                'hint' => 'Telefoni mavjud mijozniki bilan bir xil bo‘lsa o‘tkazib yuboriladi. Telefonsiz qatorlar takrorlanishi mumkin.',
            ],
            'opening_stock' => [
                'label' => 'Boshlang‘ich qoldiqlar',
                'permissions' => ['warehouses.create', 'warehouses.approve'],
                'columns' => [
                    'sku' => ['Kod (SKU)', ['sku', 'kod'], true],
                    'quantity' => ['Miqdor', ['quantity', 'qty', 'soni'], true],
                    'cost' => ['Tannarx (birlik narxi)', ['cost', 'tannarx', 'narx', 'birliknarxi'], true],
                    'batch_number' => ['Partiya raqami', ['batchnumber', 'partiya', 'partiyaraqami', 'lot'], false],
                    'expires_at' => ['Yaroqlilik muddati', ['expiresat', 'yaroqlilikmuddati', 'expiry', 'muddat'], false],
                ],
                'hint' => 'Mahsulotlar oldindan kiritilgan bo‘lishi kerak. Partiya raqami va yaroqlilik muddati ixtiyoriy; tanlangan omborga tasdiqlangan boshlang‘ich qoldiq hujjati yaratiladi.',
            ],
        ];
    }

    // ------------------------------------------------------------------ shablon

    public function template(string $type): string
    {
        $def = $this->types()[$type];
        $path = tempnam(sys_get_temp_dir(), 'tpl');
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Import');
        $writer->addRow(Row::fromValues(array_map(fn($c) => $c[0], array_values($def['columns'])), (new Style())->setFontBold()));
        $writer->addNewSheetAndMakeItCurrent()->setName('Yoriqnoma');
        $writer->addRow(Row::fromValues([$def['label'] . ' importi']));
        $writer->addRow(Row::fromValues([$def['hint']]));
        $writer->addRow(Row::fromValues(['Majburiy ustunlar: ' . implode(', ', array_map(fn($c) => $c[0], array_filter($def['columns'], fn($c) => $c[2])))]));
        $writer->addRow(Row::fromValues(['Birinchi varaq ("Import") ishlatiladi; birinchi qator — ustun sarlavhalari. Eng ko‘pi bilan ' . self::MAX_ROWS . ' qator.']));
        $writer->close();

        return $path;
    }

    // ------------------------------------------------------------------ o'qish

    /** @return array{rows: array<int, array{row:int, data:array<string,mixed>}>, errors: array} */
    public function parse(string $type, UploadedFile $file): array
    {
        $def = $this->types()[$type];
        $path = $file->getRealPath();
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xlsx' && file_get_contents($path, false, null, 0, 2) !== 'PK') {
            return ['rows' => [], 'errors' => [['row' => 0, 'messages' => ['Fayl haqiqiy .xlsx emas.']]]];
        }

        try {
            if ($ext === 'csv') {
                $opts = new CsvOptions();
                $opts->FIELD_DELIMITER = $this->delimiter($path);
                $opts->SHOULD_PRESERVE_EMPTY_ROWS = true;
                $reader = new CsvReader($opts);
            } else {
                $opts = new XlsxOptions();
                $opts->SHOULD_PRESERVE_EMPTY_ROWS = true;
                $reader = new XlsxReader($opts);
            }
            $reader->open($path);
            $all = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    $all[] = [$n, $row->toArray()];
                    if ($n > self::MAX_ROWS + 1) {
                        break;
                    }
                }
                break; // faqat birinchi varaq
            }
            $reader->close();
        } catch (\Throwable) {
            return ['rows' => [], 'errors' => [['row' => 0, 'messages' => ['Faylni o‘qib bo‘lmadi. .xlsx yoki .csv ekanini tekshiring.']]]];
        }

        if ($all === []) {
            return ['rows' => [], 'errors' => [['row' => 0, 'messages' => ['Fayl bo‘sh.']]]];
        }

        // Sarlavhalarni ustun kalitlariga moslash.
        $map = [];
        foreach ($def['columns'] as $key => [$label, $aliases]) {
            foreach (array_merge([$label], $aliases) as $a) {
                $map[$this->norm($a)] = $key;
            }
        }
        $colIndex = [];
        foreach ($all[0][1] as $i => $h) {
            $k = $map[$this->norm($this->text($h))] ?? null;
            if ($k !== null && ! isset($colIndex[$k])) {
                $colIndex[$k] = $i;
            }
        }
        $missing = [];
        foreach ($def['columns'] as $key => [$label,, $required]) {
            if ($required && ! isset($colIndex[$key])) {
                $missing[] = $label;
            }
        }
        if ($missing) {
            return ['rows' => [], 'errors' => [['row' => 1, 'messages' => ['Majburiy ustun topilmadi: ' . implode(', ', $missing)]]]];
        }

        $rows = [];
        foreach (array_slice($all, 1) as [$n, $cells]) {
            $data = [];
            $empty = true;
            foreach ($def['columns'] as $key => $_) {
                $v = isset($colIndex[$key]) ? ($cells[$colIndex[$key]] ?? null) : null;
                if ($v instanceof \DateTimeInterface) {
                    $v = $v->format('Y-m-d');
                }
                $data[$key] = $v;
                if ($this->text($v) !== '') {
                    $empty = false;
                }
            }
            if (! $empty) {
                $rows[] = ['row' => $n, 'data' => $data];
            }
        }
        if (count($rows) > self::MAX_ROWS) {
            return ['rows' => [], 'errors' => [['row' => 0, 'messages' => ['Qatorlar soni ' . self::MAX_ROWS . ' dan oshmasligi kerak. Faylni bo‘lib yuklang.']]]];
        }
        if ($rows === []) {
            return ['rows' => [], 'errors' => [['row' => 0, 'messages' => ['Faylda ma’lumot qatorlari yo‘q.']]]];
        }

        return ['rows' => $rows, 'errors' => []];
    }

    private function delimiter(string $path): string
    {
        $line = (string) strtok((string) file_get_contents($path, false, null, 0, 4096), "\n");

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function norm(string $s): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s));
    }

    private function text(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return sprintf('%.0f', $v); // 4780001234567 kabi kodlar 4.78E+12 bo'lib ketmasin
        }

        return trim(str_replace("\u{00A0}", ' ', (string) $v));
    }

    /** Raqam: 12500 | 12 500,50 | 12500.5 => "12500.50"; noto'g'ri bo'lsa null. */
    private function number(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            return number_format((float) $v, 4, '.', '');
        }
        $s = str_replace([' ', "\u{00A0}"], '', trim((string) $v));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',') && ! str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return preg_match('/^\d+(\.\d+)?$/', $s) ? $s : null;
    }

    // ------------------------------------------------------------------ tekshiruv

    /**
     * @param  array  $ctx  opening_stock uchun: warehouse_id
     * @return array{items: array, errors: array, warnings: array, skipped: int, total_rows: int, new_categories: array}
     */
    public function validate(string $type, array $rows, array $ctx = []): array
    {
        $r = match ($type) {
            'products' => $this->validateProducts($rows),
            'customers' => $this->validateCustomers($rows),
            'opening_stock' => $this->validateOpening($rows, $ctx),
        };
        $r['total_rows'] = count($rows);

        return $r;
    }

    private function fail(array &$errors, int $row, string $msg): void
    {
        foreach ($errors as &$e) {
            if ($e['row'] === $row) {
                $e['messages'][] = $msg;

                return;
            }
        }
        $errors[] = ['row' => $row, 'messages' => [$msg]];
    }

    private function validateProducts(array $rows): array
    {
        $units = [];
        foreach (Unit::all() as $u) {
            $units[mb_strtolower($u->short_name)] = $u;
            $units[mb_strtolower($u->name)] = $u;
        }
        $categories = Category::pluck('id', 'name')->mapWithKeys(fn($id, $n) => [mb_strtolower($n) => $id])->all();
        $skus = Product::withTrashed()->pluck('sku')->map(fn($s) => mb_strtolower($s))->flip()->all();
        $barcodes = Product::withTrashed()->whereNotNull('barcode')->pluck('sku', 'barcode')->mapWithKeys(fn($sku, $b) => [mb_strtolower($b) => mb_strtolower($sku)])->all();

        $items = [];
        $errors = [];
        $skipped = 0;
        $seenSku = [];
        $seenBarcode = [];
        $newCats = [];

        foreach ($rows as ['row' => $n, 'data' => $d]) {
            $sku = $this->text($d['sku']);
            $name = $this->text($d['name']);
            $barcode = $this->text($d['barcode']);
            $skuKey = mb_strtolower($sku);

            // Mavjud mahsulot: o'zgartirilmaydi va uning qatoridagi boshqa ma'lumotlar tekshirilmaydi.
            if ($sku !== '' && isset($skus[$skuKey])) {
                $skipped++;

                continue;
            }

            if ($sku === '') {
                $this->fail($errors, $n, 'Kod (SKU) bo‘sh.');
            } elseif (mb_strlen($sku) > 60) {
                $this->fail($errors, $n, 'Kod (SKU) 60 belgidan oshmasligi kerak.');
            }
            if ($name === '' || mb_strlen($name) > 200) {
                $this->fail($errors, $n, 'Nomi majburiy (200 belgigacha).');
            }
            if ($sku !== '' && isset($seenSku[$skuKey])) {
                $this->fail($errors, $n, "Kod “{$sku}” faylda takrorlangan (qator {$seenSku[$skuKey]}).");
            }
            if ($sku !== '') {
                $seenSku[$skuKey] ??= $n;
            }

            if ($barcode !== '') {
                $bk = mb_strtolower($barcode);
                if (mb_strlen($barcode) > 64) {
                    $this->fail($errors, $n, 'Shtrix-kod 64 belgidan oshmasligi kerak.');
                } elseif (isset($seenBarcode[$bk]) && $seenBarcode[$bk] !== $skuKey) {
                    $this->fail($errors, $n, "Shtrix-kod “{$barcode}” faylda boshqa mahsulotda ham bor.");
                } elseif (isset($barcodes[$bk]) && $barcodes[$bk] !== $skuKey) {
                    $this->fail($errors, $n, "Shtrix-kod “{$barcode}” boshqa mahsulotda mavjud.");
                }
                $seenBarcode[$bk] ??= $skuKey;
            }

            $unitName = $this->text($d['unit']);
            $unit = $units[mb_strtolower($unitName)] ?? null;
            if (! $unit) {
                $this->fail($errors, $n, $unitName === '' ? 'O‘lchov birligi bo‘sh.' : "O‘lchov birligi “{$unitName}” tizimda yo‘q.");
            }

            $prices = [];
            foreach (['purchase_price' => 'Xarid narxi', 'sale_price' => 'Sotuv narxi', 'wholesale_price' => 'Ulgurji narx'] as $k => $label) {
                $raw = $this->text($d[$k]);
                if ($raw === '') {
                    if ($k === 'sale_price') {
                        $this->fail($errors, $n, 'Sotuv narxi majburiy.');
                    }
                    $prices[$k] = $k === 'purchase_price' ? '0.00' : null;

                    continue;
                }
                $num = $this->number($d[$k]);
                if ($num === null || (float) $num < 0 || (float) $num > self::MONEY_MAX) {
                    $this->fail($errors, $n, "$label noto‘g‘ri: “{$raw}”.");
                } else {
                    $prices[$k] = Dec::str(Dec::units($num, 2), 2);
                }
            }

            $minRaw = $this->text($d['min_stock']);
            $min = '0.000';
            if ($minRaw !== '') {
                $num = $this->number($d['min_stock']);
                if ($num === null || (float) $num > 999999999999) {
                    $this->fail($errors, $n, "Minimal qoldiq noto‘g‘ri: “{$minRaw}”.");
                } else {
                    $min = Dec::str(Dec::units($num, 3), 3);
                    if ($unit && ! $unit->allow_fraction && Dec::units($num, 3) % 1000 !== 0) {
                        $this->fail($errors, $n, "“{$unit->name}” birligida kasrli minimal qoldiq mumkin emas.");
                    }
                }
            }

            $category = $this->text($d['category']);
            if (mb_strlen($category) > 120) {
                $this->fail($errors, $n, 'Kategoriya nomi 120 belgidan oshmasligi kerak.');
            } elseif ($category !== '' && ! isset($categories[mb_strtolower($category)])) {
                $newCats[mb_strtolower($category)] ??= $category;
            }
            $desc = $this->text($d['description']);
            if (mb_strlen($desc) > 2000) {
                $this->fail($errors, $n, 'Tavsif 2000 belgidan oshmasligi kerak.');
            }

            if (! array_filter($errors, fn($e) => $e['row'] === $n)) {
                $items[] = [
                    'row' => $n,
                    'sku' => $sku,
                    'name' => $name,
                    'barcode' => $barcode ?: null,
                    'category' => $category ?: null,
                    'unit_id' => $unit->id,
                    'purchase_price' => $prices['purchase_price'],
                    'sale_price' => $prices['sale_price'],
                    'wholesale_price' => $prices['wholesale_price'],
                    'min_stock' => $min,
                    'description' => $desc ?: null,
                ];
            }
        }

        return ['items' => $items, 'errors' => $errors, 'warnings' => [], 'skipped' => $skipped, 'new_categories' => array_values($newCats)];
    }

    private function validateCustomers(array $rows): array
    {
        $phones = Customer::whereNotNull('phone')->pluck('phone')->map(fn($p) => preg_replace('/\D+/', '', $p))->filter()->flip()->all();
        $items = [];
        $errors = [];
        $skipped = 0;
        $noPhone = 0;

        foreach ($rows as ['row' => $n, 'data' => $d]) {
            $name = $this->text($d['name']);
            $phone = $this->text($d['phone']);
            $address = $this->text($d['address']);
            $note = $this->text($d['note']);
            if ($name === '' || mb_strlen($name) > 150) {
                $this->fail($errors, $n, 'Ism / kompaniya majburiy (150 belgigacha).');
            }
            if (mb_strlen($phone) > 40) {
                $this->fail($errors, $n, 'Telefon 40 belgidan oshmasligi kerak.');
            }
            if (mb_strlen($address) > 255) {
                $this->fail($errors, $n, 'Manzil 255 belgidan oshmasligi kerak.');
            }
            if (array_filter($errors, fn($e) => $e['row'] === $n)) {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $phone);
            if ($digits !== '') {
                if (isset($phones[$digits])) {
                    $skipped++;

                    continue;
                }
                $phones[$digits] = true;
            } else {
                $noPhone++;
            }
            $items[] = ['row' => $n, 'name' => $name, 'phone' => $phone ?: null, 'address' => $address ?: null, 'note' => $note ?: null];
        }
        $warnings = $noPhone > 0 ? ["$noPhone ta mijozda telefon yo‘q: takrorlanishni tekshirib bo‘lmaydi."] : [];

        return ['items' => $items, 'errors' => $errors, 'warnings' => $warnings, 'skipped' => $skipped, 'new_categories' => []];
    }

    private function validateOpening(array $rows, array $ctx): array
    {
        $warehouse = Warehouse::find($ctx['warehouse_id'] ?? 0);
        if (! $warehouse || ! $warehouse->is_active) {
            return ['items' => [], 'errors' => [['row' => 0, 'messages' => ['Ombor topilmadi yoki faol emas.']]], 'warnings' => [], 'skipped' => 0, 'new_categories' => []];
        }
        $products = Product::withTrashed()->with('unit')->get()->keyBy(fn($p) => mb_strtolower($p->sku));
        $items = [];
        $errors = [];
        $seen = [];

        foreach ($rows as ['row' => $n, 'data' => $d]) {
            $sku = $this->text($d['sku']);
            $key = mb_strtolower($sku);
            $product = $products[$key] ?? null;
            if ($sku === '' || ! $product) {
                $this->fail($errors, $n, $sku === '' ? 'Kod (SKU) bo‘sh.' : "Mahsulot “{$sku}” topilmadi (avval mahsulotlarni import qiling).");
            } elseif ($product->trashed()) {
                $this->fail($errors, $n, "Mahsulot “{$sku}” arxivda.");
            } elseif (isset($seen[$key])) {
                $this->fail($errors, $n, "Mahsulot “{$sku}” faylda takrorlangan (qator {$seen[$key]}).");
            }
            $seen[$key] ??= $n;

            $qty = $this->number($d['quantity']);
            if ($qty === null || Dec::units($qty, 3) <= 0 || (float) $qty > 999999999999) {
                $this->fail($errors, $n, 'Miqdor 0 dan katta son bo‘lishi kerak.');
            } elseif ($product && ! $product->unit->allow_fraction && Dec::units($qty, 3) % 1000 !== 0) {
                $this->fail($errors, $n, "“{$product->name}” butun miqdorda hisoblanadi.");
            }
            $cost = $this->number($d['cost']);
            if ($cost === null || (float) $cost < 0 || (float) $cost > self::MONEY_MAX) {
                $this->fail($errors, $n, 'Tannarx 0 yoki undan katta son bo‘lishi kerak.');
            }
            $batchNumber = mb_substr($this->text($d['batch_number'] ?? ''), 0, 100);
            $expiresAt = $this->text($d['expires_at'] ?? '');
            if ($expiresAt !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiresAt);
                if (!$date || $date->format('Y-m-d') !== $expiresAt) $this->fail($errors, $n, 'Yaroqlilik muddati YYYY-MM-DD formatida bo‘lishi kerak.');
                elseif ($date < new \DateTimeImmutable(($ctx['doc_date'] ?? now()->toDateString()).' 00:00:00')) $this->fail($errors, $n, 'Yaroqlilik muddati hujjat sanasidan oldin bo‘lishi mumkin emas.');
            }

            if (! array_filter($errors, fn($e) => $e['row'] === $n)) {
                $items[] = [
                    'row' => $n,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity' => Dec::str(Dec::units($qty, 3), 3),
                    'cost' => Dec::str(Dec::units($cost, 2), 2),
                    'batch_number' => $batchNumber,
                    'expires_at' => $expiresAt !== '' ? $expiresAt : null
                ];
            }
        }

        $warnings = [];
        if ($items) {
            $have = WarehouseStock::where('warehouse_id', $warehouse->id)->whereIn('product_id', array_column($items, 'product_id'))->where('quantity', '>', 0)->count();
            if ($have > 0) {
                $warnings[] = "$have ta mahsulotning bu omborda allaqachon qoldig‘i bor: import ularga QO‘SHILADI (almashtirmaydi).";
            }
        }

        return ['items' => $items, 'errors' => $errors, 'warnings' => $warnings, 'skipped' => 0, 'new_categories' => []];
    }

    // ------------------------------------------------------------------ yozish

    /** Tekshiruvdan o'tgan qatorlarni bitta tranzaksiyada yozadi. @throws BusinessRuleException */
    public function commit(string $type, array $items, array $ctx, User $user): array
    {
        return DB::transaction(function () use ($type, $items, $ctx, $user) {
            return match ($type) {
                'products' => $this->commitProducts($items),
                'customers' => $this->commitCustomers($items),
                'opening_stock' => $this->commitOpening($items, $ctx, $user),
            };
        });
    }

    private function commitProducts(array $items): array
    {
        $categories = Category::pluck('id', 'name')->mapWithKeys(fn($id, $n) => [mb_strtolower($n) => $id])->all();
        $created = 0;
        foreach ($items as $it) {
            $categoryId = null;
            if ($it['category']) {
                $k = mb_strtolower($it['category']);
                $categories[$k] ??= Category::create(['name' => $it['category']])->id;
                $categoryId = $categories[$k];
            }
            Product::create([
                'sku' => $it['sku'],
                'barcode' => $it['barcode'],
                'name' => $it['name'],
                'category_id' => $categoryId,
                'unit_id' => $it['unit_id'],
                'purchase_price' => $it['purchase_price'],
                'sale_price' => $it['sale_price'],
                'wholesale_price' => $it['wholesale_price'],
                'min_stock' => $it['min_stock'],
                'description' => $it['description'],
                'is_active' => true,
            ]);
            $created++;
        }

        return ['created' => $created];
    }

    private function commitCustomers(array $items): array
    {
        foreach ($items as $it) {
            Customer::create(['name' => $it['name'], 'phone' => $it['phone'], 'address' => $it['address'], 'note' => $it['note'], 'is_active' => true]);
        }

        return ['created' => count($items)];
    }

    private function commitOpening(array $items, array $ctx, User $user): array
    {
        $doc = $this->stock->saveDraft([
            'type' => 'opening',
            'warehouse_id' => (int) $ctx['warehouse_id'],
            'doc_date' => $ctx['doc_date'] ?? now()->toDateString(),
            'note' => 'Excel import: boshlang‘ich qoldiq' . (isset($ctx['filename']) ? ' (' . mb_substr($ctx['filename'], 0, 80) . ')' : ''),
            'items' => array_map(fn($i) => ['product_id' => $i['product_id'], 'quantity' => $i['quantity'], 'price' => $i['cost'], 'batch_number' => $i['batch_number'] ?? null, 'expires_at' => $i['expires_at'] ?? null, 'received_at' => $ctx['doc_date'] ?? now()->toDateString()], $items),
        ], $user);
        $doc = $this->stock->approve($doc->id, $user);

        return ['created' => count($items), 'document' => ['id' => $doc->id, 'number' => $doc->number]];
    }
}
