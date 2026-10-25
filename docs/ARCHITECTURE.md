# Store ERP Architecture

## Business modules

1. Authentication
2. Dashboard
3. Warehouse
4. Documents
5. Cashbox
6. Bank
7. Sales / POS
8. Counterparties
9. Employees
10. Work schedules
11. Cashier shifts
12. Expenses
13. Reports
14. Notifications
15. Branches
16. Audit log
17. Settings

## Core business flow

Purchase:
Supplier -> Purchase document -> Stock + -> Supplier balance -> Payment -> Reports

Sale:
Customer -> Sale document -> Payment -> Stock - -> Cash/Bank + -> Debt if needed -> Profit -> Reports

Shift:
Work schedule -> Employee -> Open shift -> Transactions -> Close shift -> Expected/actual cash -> Difference -> Handover

## Principles

- Every important operation is represented as a business document/transaction.
- Stock and finance changes are recorded transactionally.
- Employee schedule and cashier shift are separate concepts.
- Multi-branch support is planned from the beginning.
- Audit history is part of the architecture.
