# Phase 8 report: Security and performance

Date: 2026-10-12 · Status: **complete, verified in a test environment**

## What was done

| Spec item | Result | Details |
|---|---|---|
| Authorization review | All 127 routes read end to end. No high or medium holes. One low leak (another affiliate's coupon on a dashboard) and a gap for disabled accounts are fixed. | [security-review.md](security-review.md) |
| Vulnerability and input validation review | Two high configuration issues that only show in production (CSP blocking eSewa, proxies lost after config cache) and nine lower issues fixed. `composer audit` and `npm audit`: no advisories. | same |
| Provider redirect (webhook) security tests | Forged, replayed, out-of-order, amount-missing and oversized redirects are covered, plus the production CSP header | `tests/Feature/Payments`, `tests/Feature/Security` |
| Concurrency tests | Real parallel requests against a multi-worker server on MySQL. They found a single-use coupon race (redeemed 4 times by 8 checkouts), which is now fixed; five other races hold. | [load-and-concurrency.md](load-and-concurrency.md) |
| Query optimization | 50k-order load test. Added five indexes and rewrote one report query. Slow reports are 2x to 9x faster, and nothing per row hits the database again (lazy loading disabled during the test). | same |
| Queue reliability | Every notification retries with backoff and a timeout. Scheduler locks recover from a killed run. Failed jobs and expired tokens are pruned. Notifications are sent after commit, so a mail outage cannot undo money changes. | [production-config.md](production-config.md) |
| Backup and restore tests | Encrypted, checksummed backup script and a guarded restore script. Drill on the 50k-order database: all 48 tables identical, ledger reconciles clean, payout details readable. Wrong key, wrong passphrase, tampered file and a full target are all refused. | [backup-and-restore.md](backup-and-restore.md) |
| Production configuration review | Secure session and token defaults, trusted proxies, the `.env` checklist, processes, and config, route, view and event caching checked | [production-config.md](production-config.md) |

## Verification (actual results)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | 294 passed (13 new) |
| `php artisan test` on MySQL 8.0.46 | 294 passed |
| `php artisan test tests/Concurrency` on MySQL | 6 passed (the coupon test 3 runs in a row after the fix) |
| New migration up, down, up on MySQL | pass |
| `vendor/bin/pint --test`, permissions matrix check | pass |
| `composer audit`, `npm audit --omit=dev` | no advisories |
| `config:cache`, `route:cache` | both succeed |

## Left for later, and why

- **Administrator multi-factor authentication** is not built (optional in the spec). It is the most useful next security feature.
- **Registration says when an email is already taken.** Changing that is a sign-up UX decision.
- **Known scaling limits.** At ten times the launch size:
  - the one-year staff dashboard takes 5 s;
  - full-ledger sums cost under 1 s;
  - hourly maturation makes about 9 queries per commission.

  None matters at about 100 affiliates. Each has a noted fix in [load-and-concurrency.md](load-and-concurrency.md).
