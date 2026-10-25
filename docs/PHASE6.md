# 6-bosqich: Savdo va POS

Holat: 1–5-bosqichlar zip ichidagi kod bo‘yicha mavjud ekani aniqlandi (`docs/PHASE5.md`, `CashService`, `StockService`). Ular qayta yozilmadi; faqat quyida sanalgan mavjud fayllarga kichik, orqaga mos o‘zgartirishlar kiritildi.

## Nima qo‘shildi
| Qism | Tarkib |
|---|---|
| Migratsiyalar (2) | `customers` (minimal); `sales`, `sale_items`, `payments` + `stock_movements.sale_id` |
| Modellar | `Customer`, `Sale`, `SaleItem`, `Payment` |
| Servis | `Services/SaleService.php` — sotuv, bekor qilish, qaytarish, smena yig‘masi. Ombor `StockService` orqali, kassa `CashService` orqali o‘zgaradi |
| Controllerlar | `SaleController`, `PosController`, `CustomerController` (minimal) |
| Validatsiya | `Requests/Sales/CheckoutRequest`, `ReturnRequest` |
| Yordamchi | `Dec::mulDiv()` — 64 bitdan oshmaydigan aniq `a*b/c` (float yo‘q) |
| Testlar | `tests/Feature/SalesTest.php` (23 ta), `tests/Unit/DecTest.php` (4 ta) |
| Frontend | `/pos` (kassa oynasi), `/sales` (tarix, qaytarish, bekor qilish), `components/Receipt.tsx` (chek + chop etish), `app/lib/sales.ts` |

## Mavjud fayllarga o‘zgartirishlar
- `StockService`: `move()` endi umumiy `applyMove()` ga yo‘naltiriladi; savdo uchun `moveForSale()` qo‘shildi; `lockProducts()` public bo‘ldi (ixtiyoriy `$with`). Qoldiq o‘zgarishi baribir bitta joyda.
- `StockMovement` model: `sale_id`; `StockMovementController`: `sale` relyatsiyasi.
- `ShiftController`: smena javobiga `sales_summary` qo‘shildi (`SaleService::shiftSummary`).
- `routes/api.php`, `config/erp.php` (chek rekvizitlari), `.env.example` (PostgreSQL + `ERP_*` + `ADMIN_*`).
- Frontend: `AppShell` (menyu), `app/page.tsx`, `lib/documents.ts` (harakat turlari), `lib/cash.ts`, `stock-movements/page.tsx`, `shifts/page.tsx` (savdo yig‘masi bloki).
- Yangi ruxsat qo‘shilmadi: mavjud `sales.*`, `customers.*` ishlatiladi.

## Jarayon (hammasi bitta DB tranzaksiyada)
Sotuv: ochiq smena (qulflanadi) → kassa (qulflanadi) → mahsulotlar (id tartibida qulflanadi) → hisob → `sales` + `sale_items` → ombor harakati (−) → to‘lovlar → naqd bo‘lsa kassa daftariga `sale` kirimi → audit. Biror qadam xato bersa hammasi qaytariladi (masalan, qoldiq yetmasa sotuv, to‘lov va kassa yozuvi bo‘lmaydi).

## Qoidalar
- **Smena majburiy.** Sotuv, qaytarish va to‘lovli sotuvni bekor qilish uchun foydalanuvchining ochiq smenasi kerak. Ombor: kassa filialidagi yagona faol ombor yoki tanlangan ombor.
- **Narxni server belgilaydi** (`products.sale_price`). Narxni o‘zgartirish, qator/umumiy chegirma — faqat `sales.discount` ruxsati bilan (aks holda 403). Hujjat chegirmasi qatorlarga proporsional, butun tiyinda taqsimlanadi (yig‘indisi aynan chegirmaga teng).
- **To‘lovlar:** naqd, karta, Click, Payme, o‘tkazma; bir usul bir marta. Jami summadan oshmaydi (qaytimni kassir hisoblaydi, serverga faqat jami summagacha yuboriladi). Qolgan summa — qarz: `sales.debt` ruxsati va mijoz majburiy.
- **Click/Payme/o‘tkazma:** rasmiy API integratsiyasi YO‘Q. Kassir chek/tranzaksiya raqamini kiritadi (majburiy); to‘lov `is_online_verified = false` bilan saqlanadi va interfeysda “avtomatik tekshirilmagan” deb ko‘rsatiladi. Muvaffaqiyatli onlayn to‘lov deb ko‘rsatilmaydi.
- **Naqd bo‘lmagan to‘lovlar** kassa naqd pulini oshirmaydi; smena yig‘masida alohida ko‘rinadi.
- **Qarz** faqat haqiqiy to‘lanmagan summa (`sales.debt_amount`). Bekor qilingan sotuv qarzi hisobga olinmaydi.
- **Idempotency:** `client_token` (frontend avtomatik yuboradi) — qayta yuborilgan so‘rov ikkinchi sotuv yaratmaydi; token faqat o‘z egasiga qaytariladi.
- **Bekor qilish** (`sales.cancel`, sabab majburiy): ombor `reversal` harakati bilan tiklanadi, to‘lovlar bekor qiluvchining joriy smenasida teskari yoziladi (naqd — kassadan chiqim `sale_cancel`). Tasdiqlangan qaytarishi bor sotuv bekor qilinmaydi. Qaytarish hujjatini bekor qilish yo‘q (ataylab).
- **Qaytarish** (`sales.update`, sabab majburiy) asl sotuvga bog‘lanadi (`parent_id`), miqdor (sotilgan − qaytarilgan) dan oshmaydi. Qaytariladigan summa = qatorning sof summasi (chegirmadan keyin) ulushi, oxirgi qismda yaxlitlash farqi yopiladi. Avval shu sotuvning qarzi kamayadi, qolgani tanlangan usulda qaytariladi (naqd bo‘lsa — kassadan chiqim `sale_return`, kassada pul yetmasa 422).
- **Tannarx:** 4-bosqichdagi vaznli o‘rtacha (`products.avg_cost`) savdo paytida `sale_items.unit_cost` ga muzlatiladi; `sales.cogs` = tannarx jami. **Foyda = sof tushum (`total`) − `cogs`**; tushum bilan tannarx aralashmaydi. Qaytarishda asl tannarx ishlatiladi, `avg_cost` o‘zgarmaydi.
- **Ko‘rinish:** `sales.update` / `sales.cancel` / `reports.view` yo‘q foydalanuvchi (kassir) faqat o‘z sotuvlarini ko‘radi.
- **Chegaralar:** miqdor ≤ 99 999.999, narx ≤ 99 999 999.99 (64 bitli butun son hisobi to‘lib ketmasligi uchun). Savatda bir mahsulot bir marta.
- Har bir sotuv, qaytarish, bekor qilish (va rad etilgan urinish) audit jurnaliga yoziladi; qoldiq harakati `stock_movements` da (`sale_id`).

## API (`/api/v1`)
| Metod | Yo‘l | Ruxsat |
|---|---|---|
| GET | `/pos/context` (smena, omborlar, ruxsatlar, chek rekvizitlari) | sales.create |
| GET | `/pos/products?warehouse_id=&search=` (shtrix-kod/SKU aniq mosi birinchi, qoldiq bilan) | sales.create |
| POST | `/sales` (`items[]`, `payments[]`, `discount`, `customer_id`, `warehouse_id`, `note`, `client_token`) | sales.create (+ chegirma/qarz ruxsatlari ichkarida) |
| GET | `/sales` (`type, status, search, date_from, date_to, cashbox_id, shift_id, customer_id, user_id`), `/sales/{id}`, `/sales/{id}/returnable` | sales.view |
| POST | `/sales/{id}/returns` (`items[]`, `note`, `refund_method`, `reference`) | sales.update |
| POST | `/sales/{id}/cancel` (`reason`) | sales.cancel |
| GET / POST | `/customers` (`?all=1&search=`) | customers.view / create |

## O‘rnatish (1–5-bosqichlar o‘rnatilgan loyiha ustiga)
1. Zip fayllarini loyiha ustiga yozing (yoki to‘liq loyiha arxividan foydalaning).
2. `backend/`: `php artisan migrate` (2 ta yangi migratsiya). Seed shart emas. `.env` ga ixtiyoriy: `ERP_STORE_NAME`, `ERP_STORE_ADDRESS`, `ERP_STORE_PHONE`, `ERP_STORE_TIN`, `ERP_RECEIPT_FOOTER`.
3. Testlar: `php artisan test`.
4. `frontend/`: yangi paket yo‘q — `npm run build`, `npm run dev`.
5. Tartib: Mahsulotlar → Xarid (kirim) tasdiqlash → Kassa smenasini ochish → Savdo (POS).
6. Rollar: kassir sotadi (chegirma/qarzsiz); menejer chegirma, qarz, qaytarish, bekor qilish qila oladi. Rollar oldin seed qilingan bo‘lsa, ruxsatlar Rollar sahifasidan sozlanadi.

## Hozircha ataylab QILINMAGAN
- Mijozlar moduli (qarz daftari, qarz to‘lovlari, hisobot) va yetkazib beruvchi hisob-kitoblari — 7-bosqich. Hozir mijoz faqat tanlanadi/tezkor yaratiladi; qarz `sales.debt_amount` (− qaytarishlar) sifatida saqlanadi.
- Click/Payme real integratsiyasi (rasmiy API ma’lumotlari kerak), karta/o‘tkazma to‘lovlarining bank hisobiga yozilishi — 8-bosqich.
- Ulgurji narx bilan sotish, kechiktirilgan (qoldirilgan) savat, foiz bilan chegirma kiritish, tungi smena — qo‘shilmagan.
- Minimal qoldiq/kassa farqi/ruxsatsiz amal bildirishnomalari va filial bo‘yicha foydalanuvchi cheklovi — 9-bosqich (audit jurnalida hozir ham bor).
- Termal printer bilan to‘g‘ridan-to‘g‘ri aloqa yo‘q: chek brauzer chop etish oynasi orqali (80 mm) chiqariladi.

## Test holati (ANIQ)
- Backend testlar YOZILGAN (`SalesTest` 23 ta, `DecTest` 4 ta), lekin **ISHGA TUSHIRILMAGAN**: ishlab chiqish muhitida PHP va Composer yo‘q, internet yo‘q. PHP fayllari `php -l` bilan ham tekshirilmagan — faqat qavs/figurali qavs balansi skript bilan tekshirildi. `php artisan migrate` va `php artisan test` ni o‘zingizda ishga tushirib, xatolarni yuboring.
- Frontend: yangi/o‘zgargan fayllar `tsc` bilan sintaksis va nom darajasida tekshirildi (xato topilmadi). `@types/react` o‘rnatilmagani uchun to‘liq tip tekshiruvi va `next build` ishlamadi — `npm run build` ni o‘zingizda ishga tushiring.
- PostgreSQL’da qo‘lda sinalmagan; parallel sotuv poygasi (bir vaqtda ikki kassir bitta oxirgi mahsulotni sotishi) real bazada sinalmagan — qulflash (`lockForUpdate`) kodda bor.

## O‘zgargan fayllar
Yangi (backend): `database/migrations/2026_10_15_000001_create_customers_table.php`, `2026_10_15_000002_create_sales_tables.php`; `app/Models/{Customer,Sale,SaleItem,Payment}.php`; `app/Services/SaleService.php`; `app/Http/Controllers/Api/V1/{SaleController,PosController,CustomerController}.php`; `app/Http/Requests/Sales/{CheckoutRequest,ReturnRequest}.php`; `tests/Feature/SalesTest.php`; `tests/Unit/DecTest.php`.
Yangi (frontend): `app/pos/page.tsx`, `app/sales/page.tsx`, `app/lib/sales.ts`, `components/Receipt.tsx`, `.env.example`.
Mavjud, o‘zgartirilgan: `backend/app/Services/StockService.php`, `app/Support/Dec.php`, `app/Models/StockMovement.php`, `app/Http/Controllers/Api/V1/{ShiftController,StockMovementController}.php`, `routes/api.php`, `config/erp.php`, `.env.example`, `README.md`; frontend `components/AppShell.tsx`, `app/page.tsx`, `app/lib/{documents,cash}.ts`, `app/stock-movements/page.tsx`, `app/shifts/page.tsx`.
