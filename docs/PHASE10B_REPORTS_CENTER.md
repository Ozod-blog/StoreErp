# 10-bosqich (2-qism): Hisobotlar markazi, Excel/PDF eksport, tannarx qoidasi

## 1) Paketlarni o'rnating (backend papkasida)
```
composer require openspout/openspout barryvdh/laravel-dompdf
```
Bu paketlar `composer.json` va `composer.lock` ni o'zgartiradi. XAMPP'da `php.ini` da `extension=gd` va `extension=zip` yoqilgan bo'lishi kerak.

## 2) Fayllarni nusxalang
Yangi fayllar (mavjudlarni ustiga yozmaydi):
- `backend/app/Services/ReportCenter.php`, `ReportExporter.php`
- `backend/app/Http/Controllers/Api/V1/ReportCenterController.php`
- `backend/resources/views/reports/table.blade.php`
- `backend/tests/Feature/ReportCenterTest.php`
- `frontend/app/reports/page.tsx`, `frontend/app/lib/download.ts`

## 3) `backend/routes/api.php` ga qo'shing
Fayl boshiga:
```php
use App\Http\Controllers\Api\V1\ReportCenterController;
```
Mavjud `/reports/finance` qatoridan keyin (ichki `auth:sanctum` guruhida):
```php
Route::get('/reports/center', [ReportCenterController::class, 'index'])->middleware('can:reports.view');
Route::get('/reports/product-options', [ReportCenterController::class, 'productOptions'])->middleware('can:reports.view');
Route::get('/reports/center/{report}', [ReportCenterController::class, 'show'])->middleware('can:reports.view');
Route::get('/reports/center/{report}/export', [ReportCenterController::class, 'export'])->middleware('can:reports.view');
```
Menyuda "/reports" havolasi allaqachon bor (AppShell), shuning uchun frontendda o'zgartirish shart emas.

## Hisobotlar
Kunlik savdo; mahsulotlar bo'yicha sotuv; foyda va marja; xodimlar bo'yicha sotuv; ombor qoldiqlari.
Filtrlar: sana, filial, ombor, mahsulot, xodim (har hisobot faqat o'ziga tegishlilarini qo'llaydi).

## Tannarx va foyda qoidasi (yagona)
- Tannarx usuli: vaznli o'rtacha (`products.avg_cost`), mahsulot bo'yicha barcha omborlar jami (4-bosqich).
- Sotuv paytida `sale_items.unit_cost` ga yoziladi va keyin o'zgarmaydi: avg_cost keyinroq o'zgarsa ham o'tgan sotuv foydasi o'zgarmaydi.
- Qaytarishlar miqdor, tushum va tannarxdan ayriladi (asl sotuv tannarxi bilan). Bekor qilingan hujjatlar hisobga olinmaydi.
- Foyda = sof tushum − tannarx. Marja % = foyda / sof tushum × 100 (ko'rsatish uchun, saqlanmaydi).
- FIFO hozircha yo'q: u partiya (batch) jadvalini talab qiladi (keyingi funksiya: partiya va yaroqlilik muddati).

## Cheklovlar (ochiq aytilgan)
- Eksport `reports.view` ruxsati bilan ishlaydi (alohida `reports.export` ruxsati hali yo'q) va audit jurnaliga `reports.export` sifatida yoziladi.
- Foydalanuvchini faqat o'z filiali ma'lumotlari bilan cheklash butun loyihada hali amalga oshirilmagan (mavjud hisobotlarda ham yo'q). Keyingi qadamlardan biri.
- Qatorlar soni 5000 bilan cheklangan. Ombor qoldig'i hisobotida turli birliklar (kg/dona) aralashgani uchun miqdor jami ko'rsatilmaydi.
- PDFda DejaVu Sans shrifti ishlatiladi (o'zbek lotin/kirill va rus harflarini qo'llaydi).

## Test holati
Bu paket men ishlagan muhitda ISHGA TUSHIRILMAGAN (PHP, composer va npm yo'q edi). Testlar yozilgan, lekin natijasi noma'lum:
`php artisan test --filter=ReportCenterTest`
