# Store ERP — global real-time sync (boshlang‘ich integratsiya)

Ushbu patch barcha autentifikatsiyalangan `/api/v1/*` POST/PUT/PATCH/DELETE so‘rovlaridan keyin (faqat HTTP 2xx bo‘lsa) umumiy invalidatsiya hodisasini yuborish infratuzilmasini qo‘shadi. Hodisada mahsulot/mijoz/sotuv ma’lumotlarining o‘zi yuborilmaydi — faqat endpoint yo‘li, HTTP metodi, foydalanuvchi IDsi va vaqt. Hodisa private `erp.sync` kanalida uzatiladi.

## 1. Backend paketini o‘rnatish

`backend` papkasida:

```bash
composer require laravel/reverb
php artisan install:broadcasting --reverb
```

Agar buyruq mavjud `routes/channels.php` yoki `bootstrap/app.php` fayllarini o‘zgartirsa, ushbu patchdagi `ErpDataChanged` eventi, `BroadcastErpMutations` middleware va `withBroadcasting(... prefix: api/v1 ...)` sozlamalarini saqlab, takroriy broadcasting ro‘yxatdan o‘tishini olib tashlang. `composer install`/`composer update` bajarilmagani sabab paket versiyasi va integratsiya bu arxivda runtime bilan tekshirilmagan.

## 2. Frontend paketlari

`frontend` papkasida:

```bash
npm install
```

`package.json` ga `laravel-echo` va `pusher-js` qo‘shilgan.

## 3. Environment

Backend `.env` ichida Reverb o‘rnatish buyrug‘i yaratgan qiymatlarni ishlating. Odatda quyidagilar bo‘ladi:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local-app-id
REVERB_APP_KEY=local-app-key
REVERB_APP_SECRET=local-app-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

Frontend `.env.local`:

```env
NEXT_PUBLIC_REVERB_APP_KEY=local-app-key
NEXT_PUBLIC_REVERB_HOST=127.0.0.1
NEXT_PUBLIC_REVERB_PORT=8080
NEXT_PUBLIC_REVERB_WSS_PORT=443
NEXT_PUBLIC_REVERB_SCHEME=http
NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api/v1
```

Frontend va backend manzillarini haqiqiy deploy manzilingizga moslang. HTTPS deployda `https`/WSS va reverse-proxy sozlamalari kerak.

## 4. Ishga tushirish

Backend papkasida alohida terminal:

```bash
php artisan reverb:start
php artisan queue:work
```

Backend API va frontendni odatdagidek ishga tushiring. Reverb eventlarni queue orqali yuborishi mumkin, shuning uchun queue worker ishlashi shart.

## 5. Sahifalarni real vaqtda yangilash

Frontendda `useErpSync` hooki mavjud. Sahifaning mavjud yuklash funksiyasiga ulang:

```tsx
useErpSync(() => {
  void loadData(); // shu sahifaning mavjud GET/yuklash funksiyasi
});
```

Hozirgi patch global eventni barcha ochiq klientlarga yetkazish va cache'ni invalidatsiya qilish poydevorini beradi. Sahifa o‘zining lokal state'ini avtomatik yangilashi uchun uning mavjud loader funksiyasi `useErpSync` ga ulanishi kerak; 36 ta sahifaga bu ulanishlar hali avtomatik kiritilmagan. Barcha sahifalar uchun bu keyingi integratsiya bosqichida har bir modulning loader/form state'ini tekshirib ulanishi lozim — butun sahifani majburan reload qilish qilinmaydi, chunki saqlanmagan POS/form ma’lumotlari yo‘qolishi mumkin.

## Xavfsizlik eslatmalari

- Private kanalga autentifikatsiya talab qilinadi.
- Event biznes ma’lumotlarini yoki request body'ni uzatmaydi.
- Loyihada filiallar bo‘yicha ma’lumot ajratilishi bo‘lsa, `routes/channels.php` dagi kanal avtorizatsiyasiga filial/tenant siyosatini qo‘shish kerak.
- Realtime server o‘rnatilmasa yoki env berilmasa, frontenddagi oddiy API ishlashi davom etadi; WebSocket ulanishi boshlanmaydi.
