# Store ERP — Phase 1

Web-based store automation / ERP system.

## Stack
- Frontend: Next.js + TypeScript + Tailwind CSS
- Backend: Laravel API (structure/configuration prepared)
- Database: PostgreSQL
- Authentication: Laravel Sanctum (planned in Phase 2)

## Phase 1
- Professional ERP dashboard shell
- Sidebar/topbar
- Module navigation
- Login UI
- Dashboard cards and recent activity
- PostgreSQL environment template
- Backend API route structure

## Run frontend
```bash
cd frontend
npm install
npm run dev
```

## Backend
The backend directory contains the Laravel setup blueprint and API structure.
Create a Laravel application there with:
```bash
composer create-project laravel/laravel .
```
Then copy/use the provided routes and environment settings.

## Database
Create a PostgreSQL database, then configure `backend/.env` from `.env.example`.
