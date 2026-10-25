# 9-bosqich — Boshqaruv paneli, hisobotlar va audit

## Qo‘shilgan imkoniyatlar

- Bosh sahifada sana oralig‘i bo‘yicha real ma’lumotlardan hisoblangan KPI kartalari.
- Hisobotlar sahifasi: savdo, ombor qoldiqlari va moliya bo‘limlari.
- Sana oralig‘i filtri; mavjud faol filiallar bo‘lsa filial filtri.
- Savdo hisobotida savdo/qaytarish summalari, chegirmalar, kunlik natijalar va eng ko‘p sotilgan 10 mahsulot.
- Ombor hisobotida mahsulot qoldiqlari, minimal qoldiq holati va sotuv narxidagi taxminiy chakana qiymat.
- Moliya hisobotida kassa/bank pul harakati va tasdiqlangan xarajatlar kategoriyalari.
- CSV eksport (hisobotning ko‘rsatilgan ro‘yxatlari uchun).
- Mavjud audit jurnali saqlab qolindi va navigatsiyaga hisobotlar qo‘shildi.
- Filiallar moduli qo‘shildi: filial yaratish, tahrirlash, qidirish, holat bo‘yicha filtrlash va xavfsiz faolsizlantirish.
- Backend endpointlari `reports.view` ruxsati bilan himoyalangan.

## API

- `GET /api/v1/reports/dashboard`
- `GET /api/v1/reports/sales`
- `GET /api/v1/reports/inventory`
- `GET /api/v1/reports/finance`
- `GET /api/v1/reports/branch-options`
- `GET /api/v1/branches`
- `POST /api/v1/branches`
- `PATCH /api/v1/branches/{branch}`
- `DELETE /api/v1/branches/{branch}` (faolsizlantiradi, butunlay o‘chirmaydi)

Umumiy so‘rov parametrlari: `date_from`, `date_to`, `branch_id` (ixtiyoriy). Sana kiritilmasa, joriy oyning birinchi kunidan bugungacha ishlatiladi.

## Hisob-kitob izohlari

- Sof savdo = tasdiqlangan sotuvlar summasi − tasdiqlangan qaytarishlar summasi.
- Yalpi foyda hisobiy ko‘rsatkich bo‘lib, sof savdodan sotuv tannarxining sof qiymati ayiriladi.
- Operatsion natija = hisobiy yalpi foyda − tanlangan davrdagi tasdiqlangan xarajatlar.
- Ombor qiymati chakana sotuv narxida taxminiy ko‘rsatiladi; bu tannarx yoki buxgalteriya bahosi emas.
- Kassa qoldig‘i kassa daftaridagi barcha yozuvlardan, bank qoldig‘i boshlang‘ich qoldiq va tasdiqlangan bank operatsiyalaridan hisoblanadi.
- Mijoz qarzi ko‘rsatkichi taxminiy hisob bo‘lib, mijoz to‘lovlari va tasdiqlangan sotuv/qaytarish qarz qiymatlariga asoslanadi. Filialga ajratilmaydi, chunki mavjud to‘lov yozuvlarida filial bog‘lanishi yo‘q.

## Cheklovlar

- Filiallar moduli mavjud ombor/kassa/bank hisoblariga bog‘lanishlarni saqlaydi. Faol ombor, kassa yoki bank hisobi biriktirilgan filialni faolsizlantirishdan oldin bog‘lanishlarni boshqa filialga ko‘chirish yoki tegishli yozuvlarni faolsizlantirish kerak.
- Ombor ro‘yxati UI/API javobida 500 tagacha mahsulotni chiqaradi.
- Hisobotlar ma’lumotlarni tahlil qilish uchun; rasmiy buxgalteriya moliyaviy hisobotini almashtirmaydi.
