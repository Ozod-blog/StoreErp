# 4-bosqich: Ombor hujjatlari

Holat: 1–3-bosqichlar zip ichidagi kod bo‘yicha mavjud ekani aniqlandi (`docs/PHASE3.md`, 4 ta katalog controller, `warehouse_stocks`). Ular qayta yozilmadi.

## Nima qo‘shildi
Hujjat turlari (bitta `stock_documents` jadvali, `type` ustuni bilan):

| Tur | Nomi | Qoldiqqa ta’siri (tasdiqlanganda) | Ruxsat moduli |
|---|---|---|---|
| `purchase` | Xarid / kirim | + (tanlangan ombor) | `purchases.*` |
| `purchase_return` | Yetkazib beruvchiga qaytarish (asl xaridga bog‘liq) | − | `purchases.*` |
| `issue` | Chiqim (hisobdan chiqarish) | − | `warehouses.*` |
| `transfer` | Omborlararo ko‘chirish | bir ombor −, ikkinchisi + | `warehouses.*` |
| `inventory` | Inventarizatsiya | farq (+/−) | `warehouses.*` |

Holatlar: `draft` (qoralama) → `approved` (tasdiqlangan) → `cancelled`. Hujjatlar **o‘chirilmaydi**.

Backend: 3 migratsiya (`suppliers`, `products.avg_cost`, `stock_documents` + `stock_document_items` + `stock_movements`), 4 model + `Supplier`, `StockService` (yagona joy, qoldiq faqat shu yerdan o‘zgaradi), `BusinessRuleException`, `Support/Dec` (float’siz hisob), `StockDocumentRequest`, 5 controller, `StockDocumentsTest` (19 ta test).
Frontend: `/purchases`, `/purchase-returns`, `/issues`, `/transfers`, `/inventory`, `/stock-movements`; umumiy `components/DocumentsView.tsx`; menyu yangilandi.

## API (`/api/v1`)
| Metod | Yo‘l | Ruxsat |
|---|---|---|
| GET | `/purchases`, `/purchases/{id}` | purchases.view |
| POST | `/purchases` | purchases.create |
| PUT/PATCH | `/purchases/{id}` (faqat qoralama) | purchases.update |
| POST | `/purchases/{id}/approve` | purchases.approve |
| POST | `/purchases/{id}/cancel` (`reason`) | purchases.cancel |
| GET | `/purchases/{id}/returnable` | purchases.view |
| GET/POST/PUT/approve/cancel | `/stock-documents[/{id}]` | warehouses.view/create/update/approve/cancel |
| GET | `/stock-movements` (`warehouse_id, product_id, type, search, date_from, date_to`) | warehouses.view |
| GET / POST | `/suppliers` (`?all=1`) | suppliers.view / create |

Ro‘yxat filtrlari: `type, status, warehouse_id, date_from, date_to, search, page, per_page`.
Hujjat turi endpointga bog‘langan: chiqim hujjati `/purchases/...` orqali ko‘rinmaydi va tasdiqlanmaydi (404).

## Qoidalar (kodda amalga oshirilgan)
- Qoralama qoldiq va tannarxga ta’sir qilmaydi. Mahsulot formasi qoldiqni o‘zgartira olmaydi.
- Tasdiqlash bitta DB tranzaksiyada: hujjat qatori va mahsulot/qoldiq qatorlari qulflanadi (`lockForUpdate`), shuning uchun qayta yuborilgan tasdiqlash ikkinchi marta qoldiq o‘zgartirmaydi (ikkinchi so‘rov 422 oladi).
- Yaratishda `client_token` (frontend avtomatik yuboradi): bir xil token bilan qayta yuborilsa ikkinchi hujjat yaratilmaydi.
- Manfiy qoldiq mumkin emas: yetmasa 422 va hech narsa o‘zgarmaydi (tranzaksiya qaytariladi).
- Tasdiqlangan hujjat tahrirlanmaydi. Bekor qilish sabab talab qiladi va **teskari harakat** yozadi (`reversal`); asl harakatlar tarixda qoladi. Bekor qilishda qoldiq yetmasa (masalan, kirim mahsuloti allaqachon chiqarilgan) 422.
- Qaytarish asl xaridga bog‘langan; qaytarish miqdori (xarid − avval tasdiqlangan qaytarishlar) dan oshmaydi. Tasdiqlangan qaytarishi bor xaridni bekor qilib bo‘lmaydi.
- Kasrli miqdor faqat `allow_fraction` birlikda; miqdor/pul hisobi butun sonlarda (`Support/Dec`), float ishlatilmaydi (faqat o‘rtacha tannarx taxminiy hisobi).
- Arxivlangan mahsulotni hujjatga qo‘shib / tasdiqlab bo‘lmaydi; faol bo‘lmagan omborga hujjat yaratilmaydi.
- Har bir yaratish/tahrirlash/tasdiqlash/bekor qilish (va rad etilgan urinish, `result=failed`) audit jurnaliga yoziladi. Har bir qoldiq o‘zgarishi `stock_movements` da: miqdor, qoldiq, tannarx, hujjat, xodim.
- Ruxsatlar har bir route’da `can:` middleware bilan backendda tekshiriladi.

## Tannarx usuli (hujjatlashtirilgan, izchil qo‘llanadi)
Vaznli o‘rtacha, mahsulot bo‘yicha (barcha omborlar jami) — `products.avg_cost`:
- Xarid tasdiqlanganda: `yangi = (jami qoldiq × eski + miqdor × sof narx) / (jami qoldiq + miqdor)`; jami qoldiq 0 bo‘lsa — sof narx. Sof narx = qator narxi × (jami − chegirma) / jami (chegirma qatorlarga proporsional).
- Chiqim, ko‘chirish, inventarizatsiya, qaytarish `avg_cost` ni o‘zgartirmaydi; harakatga joriy tannarx yoziladi. Qaytarishda asl xaridning sof tannarxi ishlatiladi.
- 6-bosqichdagi savdo foydasi shu tannarxdan hisoblanadi (sotuv tushumi ≠ tannarx).

## Hozircha ataylab QILINMAGAN (keyingi bosqichlar)
- Xaridning to‘lov summasi (`paid_amount`, naqd/bank) hujjatda saqlanadi, lekin **kassa/bank balansiga yozilmaydi** — kassa 5-bosqichda, bank 8-bosqichda. Yetkazib beruvchi qarzi hozircha `Σ(xarid jami − to‘langan) − Σ qaytarish jami` formulasi bilan hisoblanishi mumkin; ledger va to‘lovlar 7-bosqichda.
- Mijozdan sotuvni qaytarish — 6-bosqichda (sotuvga bog‘lanadi). Bu bosqichda faqat yetkazib beruvchiga qaytarish bor.
- Filial bo‘yicha foydalanuvchi cheklovi (5.14) va minimal qoldiq bildirishnomalari — 9-bosqich.
- `suppliers` — minimal jadval va tezkor yaratish; to‘liq modul 7-bosqichda.

## Ma’lum cheklovlar
- Tasdiqlangan xaridni bekor qilganda `avg_cost` qayta hisoblanmaydi (qoldiq tiklanadi, tannarx o‘zgarmaydi).
- Inventarizatsiya faqat sanalgan mahsulotlarni tuzatadi; farq tasdiqlash paytidagi tizim qoldig‘iga nisbatan hisoblanadi.
- Rollarning boshlang‘ich ruxsatlarida omborchi (`warehouse`) hujjat yaratadi, lekin tasdiqlay olmaydi (tasdiqlash — menejer/admin). Kerak bo‘lsa Rollar sahifasidan `purchases.approve` / `warehouses.approve` bering. Mavjud bazada rollar qayta seed qilinganda o‘zgarmaydi.

## O‘rnatish (2–3-bosqichlar o‘rnatilgan loyiha ildizidan)
1. Zip ichidagi fayllarni loyiha ustiga yozing (o‘zgargan mavjud fayllar: `backend/routes/api.php`, `backend/app/Models/Product.php`, `frontend/components/AppShell.tsx`, `frontend/components/ui.tsx`).
2. `backend/`: `php artisan migrate` (yangi: 3 ta migratsiya). Yangi seeder yo‘q.
3. Testlar: `php artisan test` (SQLite xotirada; PostgreSQL shart emas).
4. `frontend/`: `npm install` (yangi paket yo‘q), `npm run build`, `npm run dev`.
5. Ishlatish tartibi: Yetkazib beruvchi → Xarid (qoralama) → Tasdiqlash → Omborlar sahifasida qoldiq va “Qoldiq harakati”.

## Test holati (ANIQ)
- Backend testlar YOZILGAN (`tests/Feature/StockDocumentsTest.php`), lekin ISHGA TUSHIRILMAGAN: ishlab chiqish muhitida PHP va Composer yo‘q. PHP fayllari sintaksis jihatidan ham mashinada tekshirilmagan (`php -l` ishlamadi). `php artisan test` ni o‘zingizda ishga tushirib, xatolarni yuboring.
- Frontend: `tsc` bilan faqat sintaksis tekshiruvi o‘tdi (sintaksis xatosi yo‘q). Paketlar o‘rnatilmagani uchun to‘liq tip tekshiruvi va `next build` ishlamadi. `npm run build` ni o‘zingizda ishga tushiring.
- PostgreSQL’da (`lockForUpdate` real ishlaydigan joyda) parallel tasdiqlash qo‘lda sinalmagan.

## O‘zgargan fayllar
Yangi (backend): 3 migratsiya; modellar `Supplier`, `StockDocument`, `StockDocumentItem`, `StockMovement`; `Services/StockService.php`; `Support/Dec.php`; `Exceptions/BusinessRuleException.php`; `Http/Requests/Stock/StockDocumentRequest.php`; controllerlar `AbstractDocumentController`, `PurchaseDocumentController`, `StockDocumentController`, `StockMovementController`, `SupplierController`; `tests/Feature/StockDocumentsTest.php`.
Yangi (frontend): `components/DocumentsView.tsx`, `app/lib/documents.ts`, sahifalar `purchases`, `purchase-returns`, `issues`, `transfers`, `inventory`, `stock-movements`.
Mavjud, o‘zgartirilgan: `backend/routes/api.php`, `backend/app/Models/Product.php` (`avg_cost` cast), `frontend/components/AppShell.tsx` (menyu), `frontend/components/ui.tsx` (`Modal wide`, holat belgilari), `docs/PHASE4.md` (yangi).
