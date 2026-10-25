# Professional ERP upgrade — Phase 11 foundation

## What was added

- Fixed interface locale to Uzbek (Latin) and removed the language selector from the preferences popover. Theme and table density preferences remain.
- Notification database storage, unread count, mark-one/read-all endpoints, notifications page, header bell with unread badge.
- Telegram recipients management API/UI. The bot token is read only from `ERP_TELEGRAM_BOT_TOKEN` on the backend. Token values are not stored in the database or returned by API endpoints.
- Queued Telegram sender job with retries and a timeout.
- Scheduler commands for SQLite backup, expiring batches, low stock, cash shift differences, customer debts with a due date, and daily sales summary.
- Global search modal opened with Ctrl+K, with server-side permission checks for product/customer/supplier search results.
- Product batch/expiry schema and listing endpoint/page.
- Price-rule schema and listing/create/update API for promotions, customer discounts and loyalty redemption rules.
- Loyalty accounts and ledger schema.
- First-run onboarding checklist page and state API.
- Sanctum API token session listing/revocation page and endpoints.
- Audit CSV export endpoint and UI button; existing audit filters are preserved.
- Table preference storage API for hidden columns, saved filters and density.
- `/health`, `/api/v1/health`, and a small `/api/v1/openapi.json` endpoint.
- Added indexes for frequent catalogue and expiry queries.

## Important implementation boundaries

This is a foundation release, not a claim that every enterprise feature is production-complete:

1. The current POS checkout still needs to be wired to evaluate `price_rules`, wholesale/retail selection, loyalty points earn/redeem, and to persist a batch allocation (FEFO) on every sale. The schema and management API alone do not change sale totals.
2. Batch balances are not yet automatically updated by purchase/transfer/sale document approval. Do not rely on batch quantities as the accounting stock source until that integration and reconciliation tests are completed.
3. The security page is deliberately honest: login throttling and Sanctum token session management are present; a full TOTP 2FA challenge/enrolment flow has not been enabled yet. Migration fields are only preparatory.
4. `erp:backup` creates a local backup for SQLite. For PostgreSQL/MySQL production deployments, configure and test provider snapshots or a dedicated `pg_dump`/`mysqldump` job; the command intentionally refuses to run an unsafe shell command.
5. Debt reminders require `sales.due_at` to be populated. Existing debt records with no due date are not considered overdue.
6. Sentry SDK integration, end-to-end virtual scrolling, generic toast provider, and table-specific hidden-column controls are still integration work. Backend preference storage is ready but not every table UI has been migrated to it.
7. `erp:daily-report`, `erp:expiry-alerts`, `erp:low-stock-alerts`, `erp:debt-reminders` and `erp:shift-difference-alerts` need Laravel scheduler running. Telegram delivery needs a queue worker and valid `ERP_TELEGRAM_BOT_TOKEN` plus at least one configured chat recipient.

## Setup

1. Copy `backend/.env.example` to `backend/.env`, generate `APP_KEY`, and set database credentials.
2. For Telegram, set `ERP_TELEGRAM_BOT_TOKEN` only in the server environment/secret manager. Never paste it into frontend code, a database field, screenshots or logs.
3. Run `php artisan migrate` from `backend` after taking a backup.
4. Start a queue worker: `php artisan queue:work --tries=3`.
5. Run scheduler locally with `php artisan schedule:work`; production should use the host scheduler to run `php artisan schedule:run` every minute.
6. Verify `/health`, `/api/v1/health`, `/api/v1/openapi.json`, then test the new endpoints using an authorized account.
7. Use PostgreSQL in production and inspect `EXPLAIN ANALYZE` on real production-shaped data before adding more indexes. Avoid running migrations directly on production without a tested backup and rollback plan.

## Suggested next hardening sprint

- Complete POS price evaluation and loyalty accounting atomically in `SaleService`/`PosController`.
- Integrate batch FEFO allocation into `StockService` and all document reversal paths.
- Implement and test full TOTP 2FA, recovery codes and login challenge UX.
- Add role-scoped Telegram routing, a test-message action, and deduplication for repeated scheduled alerts.
- Wire saved table preferences into the reusable table component, then add virtualized rendering only for large lists.
- Add Sentry SDK + PII scrubbing, automated tests for each scheduler command, and PostgreSQL CI tests.
