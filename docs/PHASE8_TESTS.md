# Phase 8 test status

Status: **not executed in this packaging environment** (PHP Composer dependencies, database service, and frontend dependencies were not provisioned here).

## Required checks before production
- `cd backend && php artisan migrate:fresh --seed` on a disposable test database only.
- `cd backend && php artisan test`.
- `cd frontend && npm ci && npm run build`.
- Verify role matrix for `bank.view/create/update/approve/cancel` and `expenses.view/create/approve/cancel`.
- Verify opening balance, approved deposit, withdrawal, transfer, insufficient balance, idempotency token, and concurrent approvals.
- Verify cash-to-bank/bank-to-cash writes exactly one cash ledger entry and one bank ledger transaction.
- Verify expense approval deducts from the selected cashbox/bank account exactly once.
- Verify approved records cannot be cancelled and all actions are audited.
