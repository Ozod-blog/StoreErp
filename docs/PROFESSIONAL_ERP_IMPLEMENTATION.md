# Professional ERP — implementation and deployment notes

This patch adds server-authoritative POS pricing/quotes, lot-based inventory movements, TOTP 2FA, database-driver-aware backups, a virtualized batch table, a shared toast provider, and client error reporting.

## POS pricing and loyalty

- POS sends `pricing_type=retail|wholesale` and uses `POST /api/v1/sales/quote` for a server-side preview.
- Checkout recalculates all amounts in `SaleService`; the browser quote is display-only and never trusted for final accounting.
- Active `price_rules` for promotions and customer/card discounts are applied on the server. Discount permissions are required only for manual price/discount overrides, not automatic promotions.
- Customers can have one `loyalty_accounts` row. The first sale for a customer without an account creates a card code. To use a card-specific discount or redeem points, the cashier must enter the card code.
- `ERP_LOYALTY_POINT_VALUE_MINOR=1` means one point is worth one minor currency unit (for UZS, one tiyin). `ERP_LOYALTY_EARN_MINOR_PER_POINT=1000` awards one point per 1,000 tiyin of net sale value. Set these values to fit your business rules.
- Cancellation reverses the sale's loyalty ledger; if already-awarded points were spent and the balance cannot be reversed safely, cancellation is blocked instead of corrupting the points balance.

## Lot/expiry tracking

- Purchase and opening-stock rows accept `batch_number`, `received_at`, and `expires_at`.
- Approved receipts create `product_batches`; sales consume non-expired batches in FEFO order (earliest expiry first). Expired lots are not sold by the normal POS flow.
- Transfers consume source lots and create corresponding lots in the destination warehouse. Issue, inventory gain/loss, purchase return, sale return and sale cancellation update the lot ledger.
- Existing positive warehouse stock is migrated into `LEGACY-{warehouse}-{product}` lots with no expiry date. This preserves current stock while enabling lot-level control. Review these legacy lots and add real expiry metadata where known.
- Do not create batches directly through the API. Use purchase/opening-stock documents so the stock ledger and lot ledger stay aligned.

## Two-factor authentication (TOTP)

- Users enable 2FA from Settings → Security, add the displayed setup key to an authenticator app, then confirm with a 6-digit code.
- Secret fields use Laravel encrypted casts and are hidden from user JSON. Recovery codes are displayed once and stored as hashes inside an encrypted field.
- Login with 2FA returns a short-lived challenge instead of a Sanctum token. `/api/v1/auth/two-factor/verify` issues the token only after a valid TOTP or recovery code.
- Keep `APP_KEY` stable and private; changing it makes encrypted 2FA secrets unreadable. Back up the app key securely.

## Database backups

- Run `php artisan erp:backup`. SQLite uses a private file copy; PostgreSQL uses `pg_dump` custom format; MySQL/MariaDB uses `mysqldump --single-transaction`.
- Install the matching CLI tool on the server (`pg_dump` or `mysqldump`) and ensure it is on `PATH` for the PHP service user.
- Set `ERP_BACKUP_ENCRYPTION_KEY` to a long random secret to encrypt backups using OpenSSL AES-256-CBC/PBKDF2. If set, OpenSSL must be installed. The encryption secret is read from process environment and is not placed in command arguments.
- Backups are stored in `storage/app/private/backups`, file permissions are restricted, and files older than 14 days are deleted. Copy backups to an independent off-server location; local backups alone do not protect against disk loss.
- Restore a PostgreSQL custom dump with `pg_restore`; restore a MySQL SQL dump with `mysql`; restore SQLite by stopping writes and replacing the database file. Decrypt `.enc` files with the same OpenSSL key before restore.
- Scheduler must run continuously (Linux cron example: `* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1`). Keep a queue worker running for queued Telegram alerts.

## Virtualized UI, toast and error monitoring

- `components/VirtualTable.tsx` virtualizes the product-batch list; it renders a bounded window and spacer rows.
- `components/ToastProvider.tsx` supplies the shared `useToast()` notification API. It is mounted in the root layout.
- Frontend uncaught errors are sent to `/api/v1/client-errors` with token-like strings redacted. The backend logs sanitized metadata by default. For Sentry delivery, install the official SDK in the deployment environment and set `SENTRY_LARAVEL_DSN` (and `NEXT_PUBLIC_SENTRY_DSN` if enabling the Sentry Next.js SDK):

  ```bash
  # backend
  composer require sentry/sentry-laravel
  # frontend, from frontend/
  npm install @sentry/nextjs
  ```

  Then follow the official Sentry Laravel/Next.js setup for the installed SDK versions. Keep DSNs and server-side secrets out of source control. Client error reporting is rate-limited and should still be tested with a staging DSN first.

## Validation checklist before production

1. Back up the database and `.env` secrets separately.
2. Run `php artisan migrate --pretend`, review the SQL, then `php artisan migrate` during a maintenance window.
3. Run `php artisan route:list`, `php artisan test`, and `php artisan erp:backup` with the real DB driver.
4. Test retail and wholesale sales, active promotion, customer-specific discount, card discount, point redemption, sale cancellation, and recovery-code one-time use.
5. Test receipt of two lots with different expiries, FEFO sale, transfer to another warehouse, purchase return, inventory loss/gain and cancellation of a stock document.
6. Verify that an expired batch is excluded from POS consumption and alerts appear for expiry dates inside `ERP_EXPIRY_ALERT_DAYS`.
7. Run `npm ci` and `npm run build` in an environment with network/package cache access. Build/test could not be run in this packaging environment because dependency installation was unavailable.
