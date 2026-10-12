# NFC Affiliates

Affiliate management system for a Nepal smart NFC card store (NPR, Asia/Kathmandu): affiliate onboarding, referral tracking, commission accounting, payouts, dashboards and reports.

**Status: release 1.0.0 candidate (Phases 1 to 9 complete).** See the [release-readiness assessment](docs/release-readiness.md) and [known limitations](docs/known-limitations.md) before going live.

What it does:

- Accounts, four staff roles, affiliates and customers, with an append-only audit log.
- Affiliate applications, staff review, suspension and versioned terms.
- A built-in store: catalogue, coupons, checkout, delivery steps and disputes.
- Referral links and codes, click tracking with bot filtering, and last-click attribution.
- eSewa and Khalti payments, confirmed only by the provider's status API, plus staff-confirmed manual payments and refunds.
- Commission rules and plans, an append-only ledger, a holding period, holds, two-person adjustments and nightly reconciliation.
- Encrypted payout details, payout requests, finance approval and manual transfers with a unique reference.
- Affiliate, staff and finance dashboards, and reports with CSV and Excel export.

Each phase report in `docs/` lists what was built and the test results; [CHANGELOG.md](CHANGELOG.md) links them all.

## Stack

- PHP 8.3+, Laravel 12, MySQL 8
- Laravel Sanctum (session cookie for the web app, bearer tokens for mobile/API clients)
- spatie/laravel-permission for roles and permissions
- React 19 + TypeScript + React Router 7 + Tailwind CSS 4, built with Vite, served by Laravel
- PHPUnit for tests

## Quick start

Full Windows guide: [docs/local-setup-windows.md](docs/local-setup-windows.md).

```bash
composer install
npm install
cp .env.example .env            # PowerShell: Copy-Item .env.example .env
php artisan key:generate
# edit .env: DB_* settings for your MySQL database
php artisan migrate --seed       # creates tables, roles and permissions only
php artisan affiliate:create-admin
npm run build                    # or `npm run dev` while developing
php artisan serve                # http://localhost:8000
php artisan queue:work           # in a second terminal: sends queued emails
php artisan schedule:work        # optional locally: commission maturation, reconciliation, retention
```

With `MAIL_MAILER=log`, verification and reset emails are written to `storage/logs/`.

Online payments are off until switched on in `.env`:

- eSewa: set `ESEWA_ENABLED=true`, `ESEWA_MODE`, `ESEWA_PRODUCT_CODE` and `ESEWA_SECRET_KEY`. For eSewa's public test merchant, use product code `EPAYTEST` with the UAT secret from eSewa's developer docs.
- Khalti: set `KHALTI_ENABLED=true`, `KHALTI_MODE` and `KHALTI_SECRET_KEY`. The sandbox key comes from test-admin.khalti.com.
- `APP_URL` must be the address customers use, because the providers send the browser back to it.
- Sandbox mode is refused in production.
- `php artisan schedule:work` (or cron in production) runs `payments:recheck`, which confirms payments for customers who closed the browser before coming back.

## Useful commands

| Command | What it does |
|---|---|
| `php artisan test` | Backend test suite (SQLite in memory by default) |
| `npm run typecheck` / `npm run lint` / `npm run build` | Frontend checks |
| `php artisan affiliate:create-admin` | Create the first Super Administrator (no default password) |
| `php artisan affiliate:create-staff --role=...` | Create an Affiliate Manager, Finance Manager or Customer Support account |
| `php artisan affiliate:set-user-status <email> disabled --reason=...` | Disable (or re-enable with `active`) an account and sign it out everywhere |
| `php artisan affiliate:permissions-matrix` | Regenerate [docs/permissions-matrix.md](docs/permissions-matrix.md) |
| `php artisan db:seed --class=DevelopmentSeeder` | Local only: one `@dev.invalid` user per role, sample affiliates, products, a coupon and commission rules |
| `php artisan affiliate:prune-referral-data` | Apply click retention (also scheduled daily) |
| `php artisan affiliate:mature-commissions` | Approve and release commissions past the holding period (also scheduled hourly) |
| `php artisan affiliate:reconcile-commissions` | Check the ledger against commissions and adjustments (also scheduled daily) |
| `php artisan payments:recheck` | Ask eSewa or Khalti about unfinished online payments (also scheduled every five minutes) |
| `scripts/backup-db.sh` / `scripts/restore-db.sh` | Encrypted database backup and checked restore ([guide](docs/backup-and-restore.md)) |
| `php artisan test tests/Concurrency` | Parallel money races against MySQL ([guide](docs/load-and-concurrency.md)) |
| `vendor/bin/pint` | Format PHP code |

## Documentation

- [Phase 0 plan](docs/phase-0-plan.md): assessment, architecture, database design, threats, phases
- [Architecture](docs/architecture.md) and [decision records](docs/adr)
- [Permissions matrix](docs/permissions-matrix.md)
- [API (OpenAPI 3.1)](docs/api/openapi.yaml)
- [Local setup on Windows](docs/local-setup-windows.md)
- [API guide](docs/api-guide.md) and [database schema with ER diagram](docs/database-schema.md)
- Guides for people: [administrator guide](docs/admin-guide.md), [affiliate guide](docs/affiliate-guide.md)
- Running it: [deployment](docs/deployment.md) (Linux and Docker Compose), [migrations](docs/migrations.md), [operations runbook](docs/operations.md), [production configuration](docs/production-config.md), [backup and restore](docs/backup-and-restore.md)
- [Security review](docs/security-review.md), [load and concurrency tests](docs/load-and-concurrency.md)
- Release: [feature checklist](docs/feature-checklist.md), [requirements and assumptions](docs/requirements-and-assumptions.md), [known limitations](docs/known-limitations.md), [release readiness](docs/release-readiness.md), [Phase 9 report](docs/phase-9-report.md)
