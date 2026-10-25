# 8-bosqich — Bank va moliya

## Qo‘shilgan qismlar
- Bank hisoblari: hisob nomi/raqami, valyuta, filial, boshlang‘ich qoldiq va faol holat.
- Bank operatsiyalari: kirim, chiqim, hisoblararo o‘tkazma, kassadan bankka va bankdan kassaga qoralama/tasdiqlash oqimi.
- Xarajatlar: kategoriya, sana, summa, naqd/bank to‘lovi, filial/xodim maydonlari, izoh va tasdiqlash.
- Laravel API: `/api/v1/bank-accounts`, `/api/v1/bank-transactions`, `/api/v1/expenses`.
- Frontend: `/bank`, `/expenses`; menyuga qo‘shildi.
- Ruxsatlar: mavjud `bank.*` va `expenses.*` katalogi ishlatiladi.

## Ishga tushirish
1. Avval mavjud loyihaning alohida zaxira nusxasini oling.
2. `backend` ichida `php artisan migrate` bajaring.
3. Frontendni qayta ishga tushiring; yangi menyu ruxsatlarga qarab ko‘rinadi.
4. Rollar sahifasida kerakli foydalanuvchiga `bank.*` va `expenses.*` ruxsatlarini bering.

## Moliyaviy xavfsizlik
- Balans tasdiqlangan bank operatsiyalaridan hisoblanadi.
- Tasdiqlangan operatsiyalar/xarajatlar bu versiyada bekor qilinmaydi; qoralama holatdagina bekor qilish mumkin. Tasdiqlangan hujjatni tahrirlash o‘rniga qarshi operatsiya (reversal) moduli alohida ishlab chiqilishi kerak.
- Pul miqdorlari DB `decimal(18,2)` ko‘rinishida saqlanadi. Mavjud loyiha `Dec` yordamchisidan foydalanadi.

## Ma’lum cheklovlar
- Kassa bilan bankka o‘tkazma kassa va tegishli bank hisobini talab qiladi; ruxsat va balans tekshiruvlarini staging muhitida sinang.
- Xarajat ilovasini yuklash (attachment upload) va hisobot eksporti ushbu bosqichda kiritilmagan.
- Qarz to‘lovlarining bank usuli mavjud party-payment oqimiga avtomatik integratsiya qilinmagan; ikki marta hisoblashning oldini olish uchun keyingi integratsiya alohida sinovdan o‘tishi kerak.
- Barcha testlar va real PostgreSQL migratsiyasi ushbu paket yaratish muhitida ishga tushirilmagan. Avval staging nusxasida tekshiring.
