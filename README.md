# NFC Affiliates

Affiliate management system for a Nepal smart NFC card store (NPR, Asia/Kathmandu): affiliate onboarding, referral tracking, commission accounting, payouts, dashboards and reports.

**Status: Phase 5 (Payouts) complete.** Built and tested so far:

- Authentication, roles and audit logging.
- Affiliate onboarding: applications, staff review, suspension, terms versions and notifications.
- A built-in store: catalog, affiliate coupons and checkout.
- Referral links, click tracking, last-click attribution and referral reports.
- Commission accounting:
  - staff-confirmed payments and refunds
  - commission rules with precedence, and commission calculation
  - an append-only ledger with balances, holds and adjustments
  - nightly reconciliation
- Payouts:
  - encrypted payout details (bank, eSewa, Khalti)
  - affiliate payout requests that reserve the available balance
  - finance approval, and manual transfer confirmation with a unique reference
  - rejected and failed payouts return the funds

Payment gateways and payout providers are not connected yet (Phase 7); payments and payouts are confirmed by staff. See [docs/phase-5-report.md](docs/phase-5-report.md), [docs/phase-4-report.md](docs/phase-4-report.md), [docs/phase-3-report.md](docs/phase-3-report.md), [docs/phase-2-report.md](docs/phase-2-report.md) and [docs/phase-1-report.md](docs/phase-1-report.md).

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
| `php artisan affiliate:permissions-matrix` | Regenerate [docs/permissions-matrix.md](docs/permissions-matrix.md) |
| `php artisan db:seed --class=DevelopmentSeeder` | Local only: one `@dev.invalid` user per role, sample affiliates, products, a coupon and commission rules |
| `php artisan affiliate:prune-referral-data` | Apply click retention (also scheduled daily) |
| `php artisan affiliate:mature-commissions` | Approve and release commissions past the holding period (also scheduled hourly) |
| `php artisan affiliate:reconcile-commissions` | Check the ledger against commissions and adjustments (also scheduled daily) |
| `php artisan payments:recheck` | Ask eSewa or Khalti about unfinished online payments (also scheduled every five minutes) |
| `vendor/bin/pint` | Format PHP code |

## Documentation

- [Phase 0 plan](docs/phase-0-plan.md): assessment, architecture, database design, threats, phases
- [Architecture](docs/architecture.md) and [decision records](docs/adr)
- [Permissions matrix](docs/permissions-matrix.md)
- [API (OpenAPI 3.1)](docs/api/openapi.yaml)
- [Local setup on Windows](docs/local-setup-windows.md)
