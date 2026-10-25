# Store ERP Backend

This directory is reserved for the Laravel API.

## Create the Laravel application

From this directory:

```bash
composer create-project laravel/laravel .
```

Then configure `.env` using `.env.example`.

Required database:
- PostgreSQL
- database name: `store_erp`

The API is versioned under `/api/v1`.

Phase 2 will add:
- Sanctum authentication
- users/roles/permissions
- branch access
- database migrations
- API controllers/services

## Profil va parol xavfsizligi
- `GET /api/v1/auth/profile` joriy foydalanuvchining profil ma’lumotlari, rollari/ruxsatlari, 2FA holati va faqat o‘ziga tegishli so‘nggi audit amallarini qaytaradi.
- `PUT /api/v1/auth/password` joriy parolni tekshiradi va kamida 8 belgili yangi parolni Laravel `Hash` orqali saqlaydi. `password_confirmation` mos kelishi shart.
- Frontenddagi `/profile` sahifasi shaxsiy ma’lumotlar, rol/ruxsatlar, faollik tarixi va parol almashtirish formasini ko‘rsatadi.
