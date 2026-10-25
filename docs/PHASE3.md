# 3-bosqich: Mahsulotlar, kategoriyalar, o‘lchov birliklari, omborlar

Holat: 2-bosqich (login, foydalanuvchilar, rollar, xodimlar, audit) mavjud ekani zip ichidagi kod bo‘yicha aniqlandi; ular qayta yozilmadi.

## Nima qo‘shildi
Backend (Laravel 12, `/api/v1`):
- Migratsiyalar: `units`, `categories`, `branches`, `warehouses`, `products`, `warehouse_stocks`.
- Modellar: Unit, Category, Branch, Warehouse, Product (SoftDeletes), WarehouseStock.
- Controllerlar: Unit, Category, Warehouse, Product. Validatsiya: `Http/Requests/Catalog/*Request.php` (yaratish va tahrirlash uchun bitta klass).
- Ruxsatlar: yangi ruxsat qo‘shilmadi, mavjud `products.*` (mahsulot, kategoriya, birlik) va `warehouses.*` ishlatiladi. Har bir route `can:` middleware bilan himoyalangan.
- Seeder `BaseDataSeeder` (demo emas): 6 ta standart birlik, "Asosiy filial", "Asosiy ombor" (WH-001). Qayta ishga tushirsa mavjudni o‘zgartirmaydi.
- Audit: barcha yaratish/tahrirlash/arxivlash amallari `AuditLogger` orqali yoziladi.
- Test: `tests/Feature/CatalogTest.php` (12 ta).

Frontend (Next.js): `/products`, `/categories`, `/units`, `/warehouses` sahifalari; menyu va bosh sahifadagi havolalar; `StatusBadge`ga "Arxivda"; `formatQty()`.

## API
| Metod | Yo‘l | Ruxsat |
|---|---|---|
| GET | `/units`, `/categories` (`?all=1` — tanlov ro‘yxati) | products.view |
| POST/PATCH/DELETE | `/units[/{id}]`, `/categories[/{id}]` | products.create/update/delete |
| GET | `/products` (`search, category_id, status, archived=1, low_stock=1, sort, dir, page`) | products.view |
| GET | `/products/{id}` | products.view |
| POST / PATCH | `/products`, `/products/{id}` | products.create / update |
| DELETE | `/products/{id}` (arxivlash) | products.delete |
| POST | `/products/{id}/restore` | products.delete |
| GET | `/warehouses`, `/warehouses/branch-options`, `/warehouses/{id}/stocks` | warehouses.view |
| POST / PATCH / DELETE | `/warehouses[/{id}]` (DELETE = faolsizlantirish) | warehouses.create / update / delete |

## Qoidalar (kodda amalga oshirilgan)
- Mahsulot/kategoriya/ombor tarixga ta’sir qiladigan o‘chirish yo‘q: arxiv yoki faolsizlantirish.
- Qoldiqli ombor faolsizlantirilmaydi; qoldiqli mahsulotning birligi o‘zgartirilmaydi; ishlatilgan birlik o‘chirilmaydi.
- Kasrli birlik (kg, l, m) bo‘lmasa, minimal qoldiq butun bo‘lishi shart.
- Bo‘sh shtrix-kod NULL saqlanadi (unique cheklov bo‘sh qatorlarda to‘qnashmaydi).
- Mahsulot formasi qoldiqni o‘zgartira olmaydi.

## O‘rnatish (loyiha ildizidan, 2-bosqich o‘rnatilgan holatda)
1. Zip ichidagi fayllarni loyihaga ustidan yozing (mavjud fayllardan faqat quyidagilar o‘zgargan, "O‘zgargan fayllar" bo‘limiga qarang).
2. `backend/`: `php artisan migrate` va `php artisan db:seed --class=BaseDataSeeder`.
3. Testlar: `php artisan test` (SQLite xotirada ishlaydi, PostgreSQL shart emas).
4. `frontend/`: `npm install` (yangi paket yo‘q), `npm run dev`.

## Test holati
- Backend testlar YOZILGAN, lekin ISHGA TUSHIRILMAGAN: ishlab chiqish muhitida PHP/Composer yo‘q edi. `php artisan test` ni o‘zingizda ishga tushiring va xatolarni yuboring.
- Frontend: faqat sintaksis tekshiruvi o‘tdi (paketlar o‘rnatilmagani uchun to‘liq `tsc`/`next build` ishlamadi). `npm run build` ni o‘zingizda ishga tushiring.

## Ma’lum cheklovlar
- Filial bo‘yicha foydalanuvchi ruxsati (5.14) hali yo‘q — filiallar CRUD va kirish nazorati keyingi bosqichlarda.
- Qoldiq ro‘yxati omborda faqat nolmas qoldiqlarni ko‘rsatadi; qoldiq harakati tarixi 4-bosqichda.
- Ustunlar bo‘yicha saralash faqat tayyor variantlar orqali (nom, yangi, narx).

## O‘zgargan fayllar
Yangi: 6 migratsiya, 6 model, 4 controller, 4 request, `BaseDataSeeder`, `CatalogTest`, 4 frontend sahifa, `docs/PHASE3.md`.
Mavjud, o‘zgartirilgan: `backend/routes/api.php`, `backend/database/seeders/DatabaseSeeder.php`, `backend/.env.example` (pgsql, ADMIN_* qo‘shildi), `frontend/components/AppShell.tsx`, `frontend/components/ui.tsx`, `frontend/app/lib/format.ts`, `frontend/app/page.tsx`, `docs/ARCHITECTURE.md`.
