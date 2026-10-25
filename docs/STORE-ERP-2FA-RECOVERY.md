# Store ERP — 2FA tiklash tartibi

## Email orqali tiklash (foydalanuvchi authenticator/backup kodlarini yo‘qotganida)
1. Login sahifasida email tiklash interfeysi `/auth/two-factor/recovery/email/start` endpointiga email yuboradi.
2. Faqat tasdiqlangan email va 2FA yoqilgan hisob uchun 6 xonali kod jo‘natiladi. Javob mavjud emailni oshkor qilmaydi.
3. `/auth/two-factor/recovery/email/verify` ga email va kod yuboriladi. Kod 5 daqiqa amal qiladi, ko‘pi bilan 5 urinish.
4. Muvaffaqiyatli tekshiruv eski TOTP va backup kodlarini bekor qiladi, barcha Sanctum tokenlarini bekor qiladi. Foydalanuvchi qayta login qilib yangi Authenticator ulashi shart.
5. `backend/.env` ichida `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` to‘g‘ri sozlangan bo‘lishi kerak.

## Administrator tiklashi
- Foydalanuvchi hali tizimga kirgan ishonchli sessiyaga ega bo‘lsa, `POST /api/v1/auth/two-factor/recovery/admin-request` ga kamida 10 belgili sabab yuboradi.
- `admin` yoki `owner` roli bor administrator `GET /api/v1/auth/two-factor/recovery/admin-requests` orqali so‘rovlarni ko‘radi.
- `POST /api/v1/auth/two-factor/recovery/admin-requests/{id}/review` body: `{"decision":"approve","review_note":"Shaxs tekshirildi"}` yoki `{"decision":"reject"}`.
- Administrator o‘z so‘rovini tasdiqlay olmaydi. Tasdiqlash 2FA sirini va backup kodlarini bekor qiladi, barcha sessiyalarni tugatadi va foydalanuvchiga email yuborishga harakat qiladi. Ishlab chiqarish muhitida administrator shaxsni tizimdan tashqari tekshirishi shart.

## Favqulodda tiklash (barcha administratorlar bloklanganida)
- Ataylab ommaviy HTTP bypass endpoint yaratilmagan.
- Hosting/server egasi shaxsiy SSH/hosting boshqaruv paneli orqali server egasi ekanini tekshiradi; avval DB backup oladi; favqulodda hodisani vaqt, operator va sabab bilan qayd qiladi.
- Faqat serverga bevosita ishonchli kirish orqali, ilovaning Laravel Tinker/Artisan muhiti yordamida aniq bitta foydalanuvchining `two_factor_secret`, `two_factor_pending_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` maydonlarini null qiladi va `tokens()` ni o‘chiradi. Buni ochiq endpoint yoki foydalanuvchi yuborgan ID asosida avtomatlashtirmang.
- So‘ng admin parolini/hosting kirish ma’lumotlarini kerak bo‘lsa almashtiring, yangi 2FA va yangi backup kodlarini o‘rnating, favqulodda hodisani audit qiling va serverga vaqtinchalik kirish huquqini bekor qiling.
- Favqulodda amaliyot faqat vakolatli server egasi bajarishi kerak; maxfiy kalit yoki parolni chat/logga yozmang.

## Ishga tushirish
`cd backend && php artisan migrate --force` (avval staging backup va test muhitida sinang). SMTP sozlamalarini tekshiring. Testlar va frontend integratsiyasi deploydan oldin bajarilishi shart.
