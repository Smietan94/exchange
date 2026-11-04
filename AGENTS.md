# Agent Guidelines

## Project

This is a PHP 8.5 / Symfony 8 currency-exchange API. Follow the existing service, repository, controller, and PHPUnit patterns. Read the relevant code and tests before changing behavior.

## Financial correctness

- Never use `float` for money or exchange-rate calculations. Use `Brick\Money` and exact decimal/rational arithmetic.
- Preserve the documented exchange order in `README.md`: calculate gross using the recorded rate and round it to the target currency's minor unit with `HALF_UP`; calculate and round spread from that gross; derive client net as gross minus spread. Client net plus company spread must equal gross.
- Apply balance and transaction-status changes atomically. Preserve conditional status transitions, wallet locks, and retry/idempotency guarantees.
- Ordinary transfers settle synchronously. Transfers requiring antifraud review must not settle until explicitly approved; rejection restores the reservation only once. The antifraud threshold is strictly greater than EUR 15,000, evaluated against the unrounded EUR value.
- Archived wallets must not participate in new deposits or transfers. Preserve their transaction history. A wallet can only be archived with zero balance and no pending or fraud-review transactions. The database must continue enforcing at most one active wallet per user and currency.

## Changes and migrations

- Make the smallest complete change; preserve unrelated work in the working tree and do not revert user changes.
- Add or update tests for behavior changes, including boundary, failure, and database-concurrency cases where relevant.
- Do not edit migrations that may already have been applied. Add a new migration for schema changes and verify it against MariaDB.
- Do not run destructive database operations, such as dropping or resetting a database, without explicit user approval.
- Do not commit unless explicitly requested.
- Do not expose, overwrite, or commit credentials or local environment configuration. Avoid changing `.env` for machine-specific settings.
- For API input errors, follow existing response conventions and return client errors for malformed JSON or invalid field types. Map only the relevant unique-constraint violation to `409`; do not turn unrelated database errors into conflicts.

## Tests

Run the PHPUnit suite in the PHP container:

```bash
docker exec php-fpm composer tests
```

Database-backed tests require the configured MariaDB test database. Do not silently skip them or report the suite as fully passing when tests were skipped; report the exact failure or skip reason. Run focused tests during development, then the full suite when feasible. Report commands and actual results.

## Completion

Before finishing, inspect the diff for unrelated changes and run `git diff --check`. Summarize changed behavior, files, tests run, and any remaining limitations.
