# Phase 9 changelog

- Added `ReportController` with dashboard, sales, inventory, finance and active branch-option endpoints.
- Added `reports.view`-protected API routes.
- Replaced placeholder dashboard with period/branch filters and real KPI cards.
- Added `/reports` page with Sales, Inventory and Finance tabs.
- Added CSV export for currently displayed report rows.
- Added report API feature tests.
- Added phase documentation and calculation caveats.
- Preserved the existing audit log module.


## Qo‘shimcha yangilanish — Filiallar
- `backend/app/Http/Controllers/Api/V1/BranchController.php` — filial CRUD va faolsizlantirish API.
- `backend/app/Http/Requests/Catalog/BranchRequest.php` — server-side validatsiya.
- `backend/database/migrations/2026_10_16_000001_add_contact_fields_to_branches_table.php` — kod, telefon va mas’ul rahbar maydonlari; mavjud filial kodlari avtomatik to‘ldiriladi.
- `frontend/app/branches/page.tsx` — filiallar ro‘yxati, yaratish/tahrirlash modal oynasi, qidiruv va holat filtri.
- `frontend/components/AppShell.tsx` — ruxsatga asoslangan “Filiallar” menyusi.
- `backend/tests/Feature/BranchesTest.php` — API CRUD, ruxsat va arxivlash testlari.
