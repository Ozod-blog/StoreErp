# 5-bosqich: Kassalar, smenalar va ish jadvali

Holat: 1–4-bosqichlar zip ichidagi kod bo‘yicha mavjud ekani aniqlandi (`docs/PHASE4.md`, `StockService`, `stock_documents`). Ular qayta yozilmadi.

## Nima qo‘shildi
| Qism | Tarkib |
|---|---|
| Migratsiyalar (4) | `cashboxes`, `cashbox_shifts`, `cash_transactions`, `work_schedules` |
| Modellar | `Cashbox`, `CashboxShift`, `CashTransaction`, `WorkSchedule` |
| Servis | `Services/CashService.php` — kassa balansi **faqat shu yerdan** o‘zgaradi (`StockService` kabi yagona joy) |
| Controllerlar | `CashboxController`, `CashTransactionController`, `ShiftController`, `WorkScheduleController` |
| Validatsiya | `Http/Requests/Cash/*` (5 ta FormRequest) |
| Test | `tests/Feature/CashShiftsTest.php` (22 ta) |
| Frontend | `/cashboxes`, `/shifts`, `/work-schedules`; `components/CashParts.tsx`, `app/lib/cash.ts`; menyu va bosh sahifa havolalari |
| Seeder | `BaseDataSeeder` ga “Asosiy kassa” (KS-001) qo‘shildi (demo emas, `firstOrCreate`) |

Yangi ruxsat qo‘shilmadi: `PermissionCatalog` dagi mavjud `cashboxes.*`, `shifts.*` va `employees.*` ishlatiladi.

## Asosiy qarorlar
**Kassa daftari.** Kassa balansi saqlanmaydi, `cash_transactions` (kirim/chiqim) yig‘indisidan hisoblanadi. Yozuvlar o‘zgartirilmaydi va o‘chirilmaydi, har birida `balance_after`. Kategoriyalar: `manual_in`, `manual_out`, `opening_adjustment`, `shift_difference`. 6–8-bosqichlar (savdo, xarajat, bank) `source_type/source_id` bilan shu daftarga yozadi, yangi jadval kerak emas.

**Smena ≠ ish jadvali.** Smena — real pul harakati (`cashbox_shifts`), jadval — reja (`work_schedules`). Jadvalga ko‘ra dam kuni smena ochilsa, bloklanmaydi, javobda `warning` qaytadi.

**Smena hayot sikli:** ochish → (kirim/chiqim) → yopish yoki topshirish → (menejer ko‘rib chiqadi).
- Ochish: boshlang‘ich naqd pul sanab kiritiladi. Kassa daftaridagi balansdan farq qilsa, izoh majburiy (kassada hech qanday tarix bo‘lmagan birinchi ochilish bundan mustasno) va `opening_adjustment` yoziladi.
- Kutilayotgan naqd = boshlang‘ich + smena kirimlari − smena chiqimlari (boshlang‘ich moslashtirish hisobga olinmaydi).
- Yopish: haqiqiy naqd kiritiladi; farq bo‘lsa sabab majburiy, `shift_difference` yoziladi va kassa balansi haqiqiy summaga tenglashadi. Kutilgan/haqiqiy/farq smenada muzlatiladi.
- Topshirish: bitta tranzaksiyada joriy smena yopiladi va qabul qiluvchiga sanalgan summa bilan yangi smena ochiladi (`handover_from_shift_id`).
- Yopilgan smenani o‘zgartiradigan endpoint yo‘q. `review` (ko‘rib chiqish) faqat izoh/tasdiq qo‘shadi, `shifts.approve` kerak, bir marta.
- Bir kassada va bir xodimda bir vaqtda bitta ochiq smena: servis tekshiruvi + PostgreSQL/SQLite **qisman unique indeks** (`WHERE status = 'open'`).

**Himoya.** Chiqim balansdan oshmaydi (422). Kassa qatori `lockForUpdate` bilan qulflanadi. `client_token` bilan qayta yuborilgan kirim/chiqim ikkinchi yozuv yaratmaydi; ikkinchi yopish/ko‘rib chiqish 422 oladi. Pul `Support/Dec` (butun son) bilan hisoblanadi. Har bir pul harakati, smena ochish/yopish, ko‘rib chiqish, jadval o‘zgarishi audit jurnaliga yoziladi.

**Ruxsat qoidalari (backendda).**
- Kassir faqat o‘z smenasini ko‘radi va yopadi; boshqa xodim uchun smena ochish, boshqalar smenasini ko‘rish/yopish `shifts.approve` (menejer) talab qiladi.
- Smena ochiq bo‘lmagan kassada qo‘lda kirim/chiqim — `cashboxes.update`; ochiq smenada — smena egasi yoki `shifts.approve`.
- Kassa daftarini `shifts.approve` yoki `cashboxes.update` bo‘lmagan foydalanuvchi faqat o‘z smenalari bo‘yicha ko‘radi.

## API (`/api/v1`)
| Metod | Yo‘l | Ruxsat |
|---|---|---|
| GET | `/cashboxes` (`?all=1`, `search`, `status`), `/cashboxes/branch-options` | cashboxes.view |
| POST / PATCH / DELETE | `/cashboxes[/{id}]` (DELETE = faolsizlantirish; ochiq smena yoki qoldiq bo‘lsa 422) | cashboxes.create / update / delete |
| GET | `/cash-transactions` (`cashbox_id, shift_id, direction, category, date_from, date_to`) | cashboxes.view |
| POST | `/cash-transactions` (`cashbox_id, direction, amount, note, client_token`) | shifts.update |
| GET | `/shifts`, `/shifts/current`, `/shifts/{id}` | shifts.view |
| GET | `/shifts/cashier-options` ; POST `/shifts/open` | shifts.create |
| GET | `/shifts/handover-candidates` ; POST `/shifts/{id}/close`, `/shifts/{id}/handover` | shifts.update |
| POST | `/shifts/{id}/review` | shifts.approve |
| GET | `/my-schedule` | faqat kirgan foydalanuvchi (o‘z jadvali) |
| GET | `/work-schedules` | employees.view |
| POST / DELETE | `/work-schedules[/{id}]` (POST: bir kun yoki `dates[]`, upsert) | employees.update |

## O‘rnatish (1–4-bosqichlar o‘rnatilgan loyiha ildizidan)
1. Zip ichidagi fayllarni loyiha ustiga yozing. O‘zgargan mavjud fayllar: `backend/routes/api.php`, `backend/database/seeders/BaseDataSeeder.php`, `frontend/components/AppShell.tsx`, `frontend/components/ui.tsx`, `frontend/app/page.tsx`.
2. `backend/`: `php artisan migrate` (4 ta yangi migratsiya), so‘ng `php artisan db:seed --class=BaseDataSeeder` (KS-001 kassasi uchun; mavjudlarni o‘zgartirmaydi).
3. Testlar: `php artisan test` (SQLite xotirada).
4. `frontend/`: `npm install` (yangi paket yo‘q), `npm run build`, `npm run dev`.
5. Tartib: Kassalar → Kassa smenalari → “Smenani ochish” → kirim/chiqim → “Smenani yopish” / “Topshirish”. Ish jadvali: Xodimlar bo‘limida xodim bo‘lishi kerak.
6. Rollar: kassir (`cashier`) o‘z smenasini ochadi/yopadi; menejer (`manager`) smenalarni ko‘rib chiqadi va jadval tuzadi. Rollar oldin seed qilingan bo‘lsa ruxsatlar o‘zgarmaydi — kerak bo‘lsa Rollar sahifasidan sozlang.

## Hozircha ataylab QILINMAGAN
- Savdo tushumi, qaytarishlar va naqd bo‘lmagan to‘lovlar smenaga — 6-bosqichda (`cash_transactions` ga `category=sale` yoziladi, smena yig‘masi avtomatik hisoblaydi).
- Xarajatlar, xaridning to‘lov summasi va kassa↔bank o‘tkazmalari kassa daftariga — 7–8-bosqichlarda.
- Smenadagi farq va ruxsatsiz urinish bildirishnomalari, filial bo‘yicha foydalanuvchi cheklovi — 9-bosqichda (hozircha audit jurnalida bor, “Faqat farqli” filtri smenalarda ishlaydi).
- `shifts.cancel` ruxsati ishlatilmadi: yopilgan smenani bekor qilish qoidasi aniqlanmagan.
- Ish jadvali: tungi smena (tugash vaqti boshlanishdan kichik) qo‘llab-quvvatlanmaydi.

## Test holati (ANIQ)
- Backend testlar YOZILGAN (`CashShiftsTest`, 22 ta), lekin **ISHGA TUSHIRILMAGAN**: ishlab chiqish muhitida PHP va Composer yo‘q. PHP fayllari `php -l` bilan ham tekshirilmagan. `php artisan migrate` va `php artisan test` ni o‘zingizda ishga tushirib, xatolarni yuboring.
- Frontend: `tsc` bilan yangi fayllarda sintaksis xatosi topilmadi. React tiplari o‘rnatilmagani uchun to‘liq tip tekshiruvi va `next build` ishlamadi — `npm run build` ni o‘zingizda ishga tushiring.
- Qisman unique indeks PostgreSQL’da qo‘lda sinalmagan (SQLite va PostgreSQL ikkalasi ham shu sintaksisni qo‘llaydi). Parallel smena ochish poygasi real bazada sinalmagan.

## O‘zgargan fayllar
Yangi (backend): migratsiyalar `2026_10_14_000001..4`; modellar `Cashbox`, `CashboxShift`, `CashTransaction`, `WorkSchedule`; `Services/CashService.php`; `Http/Requests/Cash/{CashboxRequest,CashTransactionRequest,OpenShiftRequest,CloseShiftRequest,WorkScheduleRequest}.php`; controllerlar `CashboxController`, `CashTransactionController`, `ShiftController`, `WorkScheduleController`; `tests/Feature/CashShiftsTest.php`.
Yangi (frontend): `app/cashboxes/page.tsx`, `app/shifts/page.tsx`, `app/work-schedules/page.tsx`, `app/lib/cash.ts`, `components/CashParts.tsx`.
Mavjud, o‘zgartirilgan: `backend/routes/api.php`, `backend/database/seeders/BaseDataSeeder.php`, `frontend/components/AppShell.tsx`, `frontend/components/ui.tsx` (“Ochiq/Yopilgan” belgilari), `frontend/app/page.tsx`.
