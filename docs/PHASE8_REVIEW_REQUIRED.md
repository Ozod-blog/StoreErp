# Phase 8 — mandatory review notes

This is an implementation package for integration testing, not a claim of production readiness. Review `FinanceService.php` before live use, especially cash transfer authorization/shift behavior, bank balance locking under concurrent writes, number generation uniqueness, and currency-specific formatting. Add automated tests for each business rule before deploying with real money.
