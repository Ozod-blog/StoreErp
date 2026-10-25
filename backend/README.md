# Store ERP — Backend (Laravel 12, PHP ^8.2, PostgreSQL)

REST API: `/api/v1`, autentifikatsiya — Laravel Sanctum (Bearer token). Ruxsatlar har bir route’da `can:modul.amal` bilan backendda tekshiriladi.

## Ishga tushirish
```bash
composer install
cp .env.example .env          # DB_*, ADMIN_PASSWORD, ERP_* qiymatlarini to'ldiring
php artisan key:generate
php artisan migrate
php artisan db:seed           # rollar/ruxsatlar, asosiy ma'lumotlar, birinchi administrator
php artisan serve
php artisan test
```
Productionda `.env` da `ADMIN_PASSWORD` majburiy (demo parol yo‘q). `.env`, tokenlar va `database.sqlite` Git/ZIP ga kiritilmaydi.

Bosqichlar bo‘yicha hujjatlar: `../docs/PHASE3.md` … `../docs/PHASE6.md`.
