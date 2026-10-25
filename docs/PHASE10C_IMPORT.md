# 10-bosqich (3-qism): Excel/CSV import (mahsulotlar, mijozlar, boshlang'ich qoldiqlar)

## 1) Yangi fayllar (nusxalang)
- `backend/app/Services/ImportService.php`
- `backend/app/Http/Controllers/Api/V1/ImportController.php`
- `backend/tests/Feature/ImportTest.php`
- `frontend/app/imports/page.tsx`, `frontend/app/lib/upload.ts`

(`openspout/openspout` paketi 10b qadamida o'rnatilgan; yangi paket kerak emas.)

## 2) Mavjud kodga o'zgartirish: `patches/opening-stock.patch`
Boshlang'ich qoldiq `opening` turidagi ombor hujjati orqali kiradi (qoldiq, o'rtacha tannarx va audit bitta joyda, `StockService`).
Buning uchun 2 ta faylga 6 ta kichik o'zgartirish kerak (`StockDocument.php` ga 1, `StockService.php` ga 5 qator).
Patch loyiha ildizidan (backend papkasi turgan joydan) shunday qo'llanadi:
```
git apply patches/opening-stock.patch
```
yoki qo'lda:
- `app/Models/StockDocument.php`: `PREFIXES` ga `'opening' => 'BQ',`
- `app/Services/StockService.php`:
  1. `saveDraft` ichida `if ($type === 'purchase') {` (narx hisoblanadigan, `$price = null;` dan keyingi qator) ->
     `if (in_array($type, ['purchase', 'opening'], true)) {`
  2. `$total = $type === 'purchase_return' ? $subtotal : 0;` ->
     `$total = in_array($type, ['purchase_return', 'opening'], true) ? $subtotal : 0;`
  3. `approve` ichidagi `match` ga `'opening' => $this->applyPurchase($doc, $products, $user, 'opening'),`
  4. `applyPurchase(...)` imzosiga `, string $moveType = 'purchase'` qo'shing
  5. uning ichida `'purchase', $netUnit, $user->id);` -> `$moveType, $netUnit, $user->id);`

## 3) `backend/routes/api.php`
Fayl boshiga: `use App\Http\Controllers\Api\V1\ImportController;`
Himoyalangan (`auth:sanctum`) guruh ichiga, hisobotlar yo'llaridan keyin:
```php
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/imports/{type}/template', [ImportController::class, 'template']);
    Route::post('/imports/{type}/preview', [ImportController::class, 'preview']);
    Route::post('/imports/{type}', [ImportController::class, 'commit']);
});
```
Ruxsat tekshiruvi controller ichida: mahsulotlar `products.create`, mijozlar `customers.create`,
boshlang'ich qoldiq `warehouses.create` + `warehouses.approve`.

## 4) Menyu
`frontend/components/AppShell.tsx`: lucide importiga `Upload` qo'shing va NAV ga qator:
```
{ href: "/imports", label: "Excel import", icon: Upload, permission: "products.create" },
```

## Qoidalar
- Ikki bosqich: "Tekshirish" (hech narsa yozmaydi) va "Import qilish" (server faylni qayta to'liq tekshiradi).
- Faylda bitta xato bo'lsa HECH NARSA yozilmaydi (qisman import yo'q). Xatolar qator raqami bilan ko'rsatiladi (dastlabki 100 ta).
- Mavjud mahsulot (kod bo'yicha, arxivdagilar ham) va mavjud mijoz (telefon raqamlari bo'yicha) o'tkazib yuboriladi, o'zgartirilmaydi.
- Yo'q kategoriya avtomatik yaratiladi; o'lchov birligi tizimda bo'lishi shart.
- Boshlang'ich qoldiq: tanlangan omborga bitta tasdiqlangan hujjat (BQ-000001) va "opening" harakatlari; o'rtacha tannarx shu narxdan boshlanadi.
  Mahsulotda qoldiq bor bo'lsa ogohlantiriladi: import QO'SHADI (almashtirmaydi). Xato bo'lsa hujjat odatdagi tartibda bekor qilinadi (bekor qilishda o'rtacha tannarx qayta hisoblanmaydi — 4-bosqichdagi ma'lum cheklov).
- Cheklovlar: fayl 5 MB, 2000 qator, faqat birinchi varaq, .xlsx yoki .csv (CSVda `,` yoki `;`, UTF-8).
- Hamma import audit jurnaliga `imports.commit` bo'lib yoziladi.

## Ochiq cheklovlar
- Mavjud mahsulotlarni import orqali YANGILASH (narx yangilash) hali yo'q — keyingi qadamga mumkin.
- Boshlang'ich qoldiq hujjati "Xaridlar" ro'yxatida ko'rinmaydi (u yerda faqat xarid/qaytarish); u "Qoldiq harakati" sahifasida `opening` harakatlari sifatida ko'rinadi.
- Telefonsiz mijozlar takror importda ikki marta yozilishi mumkin.

## Test holati
Bu paket men ishlagan muhitda ISHGA TUSHIRILMAGAN (PHP/composer/npm yo'q edi). Faqat patch fayli sizning loyihangiz nusxasiga `patch --dry-run` bilan tekshirildi.
`php artisan test --filter=ImportTest`, so'ng butun `php artisan test` va `npm run build`.
