# Phase 9 report: Release preparation

Date: 2026-10-12 · Status: **complete; release 1.0.0 is ready for a production trial once the owner's items in [release-readiness.md](release-readiness.md) are done**

## Spec items

| Spec item | Result | Where |
|---|---|---|
| Reproducible setup | A fresh clone was installed from scratch onto an empty MySQL 8 database by following the README: `composer install`, `npm ci`, `key:generate`, `migrate --seed`, `affiliate:create-admin`, `npm run build`, the four cache commands. `/up`, the app shell, built assets, sign-in, the staff dashboard and the store API all answered correctly. | [README](../README.md), [deployment.md](deployment.md) |
| Production build | `npm run build`, `composer install --no-dev --optimize-autoloader`, config/route/view/event caching. A Docker image builds the same way. | [deployment.md](deployment.md) |
| Docker Compose (optional) | nginx, PHP-FPM, queue worker, scheduler and MySQL 8, each with a health check, with persistent volumes. Only nginx publishes a port, on 127.0.0.1; MySQL is never published. Built and run: all five healthy, migrations and the first admin via `run --rm app`, sign-in, queued email sent by the worker, scheduler running `payments:recheck`, `/.env` refused, data kept across `down`/`up`. | `compose.production.yml`, `docker/`, [deployment.md §13](deployment.md#13-docker-compose) |
| Migration instructions | Every migration listed with what it creates. None changes or deletes existing data. Rollback guidance. | [migrations.md](migrations.md) |
| Deployment checklist | Linux setup: software versions, installation, least-privilege database users, `.env`, migrations, first admin and staff, permissions, supervisor, cron, nginx, certbot HTTPS, payment switch-on, log rotation, monitoring, backups, upgrade and rollback. | [deployment.md](deployment.md), [production-config.md](production-config.md) |
| Test results | Below | |
| Operational documentation | Runbook covers daily, weekly and quarterly checks, staff accounts, and common incidents: mail, failed jobs, a stuck scheduler, unpaid-looking orders, a provider outage, reconciliation, a full disk, a break-in, and restores. Administrator, affiliate and API guides. | [operations.md](operations.md), [admin-guide.md](admin-guide.md), [affiliate-guide.md](affiliate-guide.md), [api-guide.md](api-guide.md) |
| Known limitations | | [known-limitations.md](known-limitations.md) |
| Release-readiness assessment | | [release-readiness.md](release-readiness.md) |
| Section 29 deliverables | Checked one by one against the code | [feature-checklist.md](feature-checklist.md), [requirements-and-assumptions.md](requirements-and-assumptions.md), [database-schema.md](database-schema.md) |

## Fixed or added while preparing the release

Writing the guides meant walking every screen against the code, which found these:

- **Staff accounts.** Only Super Administrators could be created. New commands: `affiliate:create-staff --role=…` creates staff accounts. `affiliate:set-user-status` disables or re-enables an account; disabling deletes its tokens and sessions, and the last active Super Administrator is protected. Both are audited, with 5 tests.
- **The approval message was thrown away.** The approve dialog offered "Message to the member", but the server never saved it. It is now stored in the history and emailed as "Message from the team". Test added.
- **Clicks from in-app browsers were dropped.** Taps on referral links inside Telegram, Viber and similar apps were counted as link previews. Real browsers inside those apps are now counted, and the apps' preview fetchers still are not. Data-driven test.
- **Category errors were hidden.** Adding a category that already existed, or one named in Nepali script, failed silently. The error is now shown, and names in Nepali script get a generated web address.
- The Orders filter now has **Partly refunded** and **Refunded**.
- Reserved money now has one label, **In payout**, everywhere. It was previously "In payout", "In progress" or "Reserved".
- The affiliate page no longer says referral links are "added in the next release", and the conversion hint says "per 100 unique clicks".
- `composer.json` now requires PHP 8.3, which the locked dependencies already needed.
- The API spec is now version 1.0.0, and these entries were corrected:
  - the session cookie name
  - when the guest access token is issued
  - a missing status label
  - validation limits
- The architecture doc is up to date: directory layout, audit actions and rate limits.
- Two migration comments are corrected; the change is to comments only.

## Verification (actual results, 2026-10-12)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | **313 passed** (2,088 assertions) |
| `php artisan test` on MySQL 8.0.46 | **313 passed** |
| `php artisan test tests/Concurrency` on MySQL (real parallel requests) | **6 passed** |
| `vendor/bin/pint --test`, `affiliate:permissions-matrix --check` | pass |
| `npm run lint`, `npm run typecheck`, `npm run build` | pass (main bundle 469 kB, 133 kB gzipped) |
| `composer audit`, `npm audit --omit=dev` | no advisories |
| OpenAPI lint (Redocly) | valid |
| Clean install from a fresh clone | pass |
| Docker Compose build and run | pass (see above) |

## Not done here, and why

- **No live eSewa or Khalti call.** This build machine cannot reach the providers' sandbox hosts. The owner checklist explains how to open them.
- **No production deployment.** That needs the owner's server, domain and go-ahead.
- **Three starter GitHub workflows remain.** They came from the Laravel skeleton, and deleting CI files needs the owner's approval. See [known-limitations.md](known-limitations.md#repository-housekeeping).
