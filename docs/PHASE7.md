# 7-bosqich: Mijozlar va yetkazib beruvchilar, qarzlar, qisman to‘lovlar, o‘zaro hisob-kitob

Holat: zip ichidagi kod bo‘yicha 1–6-bosqichlar mavjud ekani aniqlandi (`docs/PHASE3–6.md`, `SaleService`, `StockService`, `CashService`). Ular qayta yozilmadi; faqat quyida sanalgan mavjud fayllarga kichik, orqaga mos o‘zgartirishlar kiritildi.

## Asosiy qaror: qarz saqlanmaydi, hujjatlardan hisoblanadi
Qarz uchun alohida balans ustuni yo‘q — shuning uchun bitta operatsiya ikki marta hisobga olinishi mumkin emas (`Services/PartyLedgerService.php` — yagona hisoblash joyi):

- **Mijoz qarzi** = Σ sotuv `debt_amount` − Σ qaytarish `debt_amount` − Σ mijoz to‘lovlari (faqat `approved`).
- **Yetkazib beruvchiga qarz** = Σ (xarid `total` − `paid_amount`) − Σ qaytarish `total` − Σ yetkazib beruvchiga to‘lovlar (faqat `approved`).
- Qoralama va bekor qilingan hujjatlar qarzga ta’sir qilmaydi. Musbat qiymat — qarz, manfiy — ortiqcha to‘lov (interfeysda “ortiqcha to‘lov”).

## Nima qo‘shildi
| Qism | Tarkib |
|---|---|
| Migratsiyalar (2) | `suppliers.contact_info`; `customer_payments`, `supplier_payments` |
| Modellar | `CustomerPayment`, `SupplierPayment` (`Supplier` ga `contact_info`) |
| Servislar | `PartyLedgerService` (qarz, xulosa, hisob-kitob varaqasi), `DebtPaymentService` (to‘lov qabul qilish/to‘lash/bekor qilish) |
| Controllerlar | `CustomerController`, `SupplierController` (to‘liq), `PartyPaymentController` |
| Validatsiya | `Requests/Parties/{CustomerRequest,SupplierRequest,PaymentRequest,CancelPaymentRequest}` |
| Testlar | `tests/Feature/PartyDebtsTest.php` (12 ta) |
| Frontend | `/customers`, `/suppliers` (bitta umumiy `components/PartyView.tsx`), `app/lib/parties.ts`, menyu va bosh sahifa havolalari, kassa kategoriyalari nomlari |

## Qoidalar
- **Qisman to‘lov:** summa qarzdan oshmaydi (yetkazib beruvchiga avans ham yo‘q). To‘lov qarz qayta hisoblanib, tarafni qulflagan holda DB tranzaksiyasida qayd etiladi.
- **Naqd to‘lov** faqat foydalanuvchining **ochiq smenasi** orqali: mijozdan — kassa daftariga kirim (`debt_payment`), yetkazib beruvchiga — chiqim (`supplier_payment`; kassada pul yetmasa 422). Naqd bo‘lmagan mijoz to‘lovlari (karta/Click/Payme/o‘tkazma) kassaga yozilmaydi; Click/Payme/o‘tkazma uchun chek raqami majburiy va `is_online_verified = false` (avtomatik tekshirilmagan deb ko‘rsatiladi).
- **Bank to‘lovi (yetkazib beruvchiga)** hozircha faqat qarzni kamaytiradi; bank hisobiga yozilishi — 8-bosqich.
- **Idempotency:** `client_token` (frontend avtomatik yuboradi) — qayta yuborilgan so‘rov ikkinchi to‘lov yaratmaydi; token boshqa foydalanuvchiga tegishli bo‘lsa 409.
- **Bekor qilish** (sabab majburiy): to‘lov o‘chirilmaydi, `cancelled` bo‘ladi; qarz tiklanadi; naqd bo‘lsa bekor qiluvchining ochiq smenasida kassa daftariga teskari yozuv (`debt_payment_cancel` / `supplier_payment_cancel`).
- **Faolsizlantirish:** qarzi (yoki ortiqcha to‘lovi) bor mijoz/yetkazib beruvchi faolsizlantirilmaydi; o‘chirish yo‘q.
- **O‘zaro hisob-kitob varaqasi:** qarzga ta’sir qiluvchi hodisalar (savdo/xarid, qaytarish, to‘lov) sana bo‘yicha, yurib boruvchi qoldiq bilan; davr filtri boshlang‘ich qoldiqni to‘g‘ri hisoblaydi; chop etish tugmasi bor. Bir soniya ichidagi hodisalar tartibi qat’iy emas (yakuniy qoldiq har doim to‘g‘ri).
- Barcha to‘lov va bekor qilishlar (rad etilgan urinishlar ham) audit jurnaliga yoziladi.

## Ruxsatlar (yangi ruxsat qo‘shilmadi — rollar qayta seed qilinishi shart emas)
| Amal | Ruxsat |
|---|---|
| Mijoz/yetkazib beruvchi ro‘yxati, kartochka, varaqa, tarix | `customers.view` / `suppliers.view` |
| Yaratish, tahrirlash, faolsizlantirish | `*.create` / `*.update` / `*.delete` |
| Mijozdan qarz to‘lovini qabul qilish | `sales.create` (kassir ham qila oladi) |
| Mijoz to‘lovini bekor qilish | `sales.cancel` |
| Yetkazib beruvchiga to‘lash | `suppliers.update` |
| Yetkazib beruvchi to‘lovini bekor qilish | `purchases.cancel` |

## API (`/api/v1`)
| Metod | Yo‘l |
|---|---|
| GET | `/customers` (`search, status, has_debt=1, sort=debt, page`; `?all=1` — POS tanlovi, o‘zgarmagan), `/customers/summary`, `/customers/{id}`, `/customers/{id}/sales`, `/customers/{id}/payments`, `/customers/{id}/statement?from&to` |
| POST / PATCH / DELETE | `/customers`, `/customers/{id}` (DELETE = faolsizlantirish) |
| POST | `/customers/{id}/payments` (`amount, method, reference, note, client_token`), `/customer-payments/{id}/cancel` (`reason`) |
| GET | `/suppliers` (`?all=1` o‘zgarmagan), `/suppliers/summary`, `/suppliers/{id}`, `/suppliers/{id}/documents`, `/suppliers/{id}/payments`, `/suppliers/{id}/statement` |
| POST / PATCH / DELETE | `/suppliers`, `/suppliers/{id}` |
| POST | `/suppliers/{id}/payments` (`method`: `cash` \| `bank`), `/supplier-payments/{id}/cancel` |

## Mavjud fayllarga o‘zgartirishlar
- `routes/api.php` (mijoz/yetkazib beruvchi route’lari kengaytirildi, eski 4 ta route o‘z joyida).
- `CustomerController`, `SupplierController` qayta yozildi: `index(?all=1)` javobi 6/4-bosqichdagi bilan bir xil; `store` endi Form Request orqali (maydonlar bir xil).
- `Models/Supplier.php` (`contact_info`), frontend `AppShell.tsx`, `app/page.tsx`, `app/lib/cash.ts` (yangi kategoriya nomlari).

## O‘rnatish (1–6-bosqichlar o‘rnatilgan loyiha ustiga)
1. Zip fayllarini loyiha ustiga yozing.
2. `backend/`: `php artisan migrate` (2 ta yangi migratsiya). Seed shart emas.
3. Testlar: `php artisan test`.
4. `frontend/`: yangi paket yo‘q — `npm run build`, `npm run dev`.
5. Sinab ko‘rish: mijozga qarzga sotuv (POS) → Mijozlar → “Ko‘rish” → “Qarz to‘lovi” (avval kassa smenasini oching).

## Hozircha ataylab QILINMAGAN / ochiq masalalar
- **Xarid hujjatidagi `paid_amount` kassa/bankka yozilmaydi** (4–5-bosqich hujjatlarida shunday kechiktirilgan edi). Ya’ni xarid paytida “naqd to‘landi” deb kiritilgan summa qarzdan ayriladi, lekin kassa balansini kamaytirmaydi. Faqat 7-bosqichdagi “To‘lov qilish” orqali qilingan to‘lovlar kassaga yoziladi. Yechim taklifi: 8-bosqichda xaridni tasdiqlashda kassa/bank hisobini tanlash. **Qaror kerak.**
- To‘lov mijozning umumiy qarziga qo‘llanadi (alohida sotuvga taqsimlanmaydi); varaqada sotuvlar va to‘lovlar xronologiyada ko‘rinadi.
- Qarz to‘langandan keyin sotuv bekor qilinsa, qoldiq manfiy (ortiqcha to‘lov) bo‘lishi mumkin; u “ortiqcha to‘lov” deb ko‘rsatiladi.
- “Yetkazib beruvchiga to‘lov muddati” va qarz bildirishnomalari — 9-bosqich (xarid hujjatida muddat maydoni hali yo‘q).
- Click/Payme real integratsiyasi — rasmiy API ma’lumotlari kerak. Hisobotlarni faylga eksport (CSV/PDF) yo‘q: faqat varaqani chop etish.
- Filial bo‘yicha foydalanuvchi cheklovi — 9-bosqich.

## Test holati (ANIQ)
- Backend testlar YOZILGAN (`PartyDebtsTest`, 12 ta), lekin **ISHGA TUSHIRILMAGAN**: ishlab chiqish muhitida PHP, Composer va internet yo‘q. PHP fayllari `php -l` bilan ham tekshirilmagan — faqat qavs balansi skript bilan tekshirildi. SQL ifodalari (qarz subquery’lari) PostgreSQL va SQLite uchun mo‘ljallangan, lekin real bazada sinalmagan.
- Frontend: yangi/o‘zgargan fayllar `tsc` bilan sintaksis darajasida tekshirildi (sintaksis xatosi yo‘q). `@types/react` o‘rnatilmagani uchun to‘liq tip tekshiruvi va `next build` ishlamadi — `npm run build` ni o‘zingizda ishga tushiring.
- Parallel to‘lovlar poygasi real bazada sinalmagan (tarafni `lockForUpdate` bilan qulflash kodda bor).

Iltimos, `php artisan migrate` va `php artisan test` natijasini yuboring.

## O‘zgargan fayllar
Yangi (backend): migratsiyalar `2026_10_16_000001_add_contact_info_to_suppliers_table.php`, `2026_10_16_000002_create_party_payments_tables.php`; `app/Models/{CustomerPayment,SupplierPayment}.php`; `app/Services/{PartyLedgerService,DebtPaymentService}.php`; `app/Http/Controllers/Api/V1/PartyPaymentController.php`; `app/Http/Requests/Parties/*` (4 ta); `tests/Feature/PartyDebtsTest.php`.
Yangi (frontend): `app/customers/page.tsx`, `app/suppliers/page.tsx`, `app/lib/parties.ts`, `components/PartyView.tsx`.
Mavjud, o‘zgartirilgan: `backend/routes/api.php`, `backend/app/Http/Controllers/Api/V1/{CustomerController,SupplierController}.php`, `backend/app/Models/Supplier.php`, frontend `components/AppShell.tsx`, `app/page.tsx`, `app/lib/cash.ts`.
