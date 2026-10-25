# Store ERP — parolni email orqali tiklash

## Qo‘shilgan endpointlar
- `POST /api/v1/auth/password/forgot` — emailga 6 xonali kod yuboradi (10 daqiqa).
- `POST /api/v1/auth/password/reset` — kodni tekshiradi, parolni yangilaydi va barcha eski Sanctum tokenlarini bekor qiladi.

Login sahifasida `Parolni unutdingizmi?` havolasi qo‘shildi. Admin ham o‘z parolini email orqali tiklay oladi; admin tizimga kira olmasa, boshqa admin uning parolini foydalanuvchilar boshqaruvi orqali yangilashi mumkin (mavjud `users.update` endpoint va ruxsatlari asosida).

## Xavfsizlik
- Email mavjudligini oshkor qilmaydigan bir xil javob.
- So‘rov va tasdiqlash urinishlari limitlangan.
- Kod Cache’da hash ko‘rinishida saqlanadi, 10 daqiqada tugaydi, 5 xato urinishdan so‘ng bekor qilinadi.
- Parol kamida 10 belgi va tasdiqlash maydoni bilan tekshiriladi.
- Parol o‘zgargach, barcha Sanctum tokenlari bekor qilinadi; qayta login talab etiladi.
- Email jo‘natish uchun `backend/.env` dagi `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` SMTP provayderingizga mos sozlangan bo‘lishi shart. `MAIL_MAILER=log` bo‘lsa xat foydalanuvchining emailiga yuborilmaydi, Laravel logiga yoziladi.

## O‘rnatish
1. Backup oling.
2. Ushbu patch ichidagi fayllarni shu yo‘llardagi fayllar bilan birlashtiring/almashtiring.
3. `cd backend && php artisan optimize:clear`
4. `cd frontend && npm run build` bilan frontendni tekshiring.
5. SMTP sozlamalarini test qiling.

> Eslatma: SMTP va real email yuborish ushbu muhitda sinovdan o‘tkazilmagan. Productionga chiqarishdan oldin email yetkazilishi, throttling va password reset integratsion testlarini bajaring.
