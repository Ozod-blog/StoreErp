# Store ERP — Authenticator 2FA

## Ishlash tartibi
1. Foydalanuvchi `Sozlamalar → Xavfsizlik` sahifasida 2FA ni yoqadi.
2. Backend vaqtinchalik TOTP kalitini yaratadi; frontend QR-kodni lokal ravishda `qrcode.react` bilan chizadi. Kalit tashqi QR xizmatiga yuborilmaydi.
3. Google Authenticator yoki Microsoft Authenticator QR-kodni skaner qiladi.
4. Ilovadagi 6 xonali, 30 soniyalik kod backendda tekshiriladi. To‘g‘ri bo‘lsa 2FA yoqiladi va 10 ta bir martalik tiklash kodi bir marta ko‘rsatiladi.
5. Keyingi loginlarda email/paroldan so‘ng TOTP kodi yoki ishlatilmagan tiklash kodi talab qilinadi.

## O‘rnatish
Frontend papkasida:

```bash
npm install
npm run build
```

Backend papkasida:

```bash
composer install
php artisan migrate --force
php artisan test
```

2FA maxfiy kalitlari `User` modelida Laravel encrypted cast bilan saqlanadi. Tiklash kodlari faqat hash ko‘rinishida saqlanadi. `.env` faylini repozitoriyga yuklamang.

## Muhim eslatma
Ushbu versiyada Authenticator QR-kodi, TOTP tasdiqlash, login paytida TOTP va bir martalik tiklash kodlari mavjud. Email/admin/favqulodda 2FA tiklash oqimlari bu paketda hali amalga oshirilmagan; ularni alohida tasdiqlash va audit bilan qo‘shish kerak.
