# Changelog

## 1.0.0 (release candidate, 2026-10-12)

First production release. Built in phases; each phase report in `docs/` lists what was built and tested.

- **Accounts and access** (Phase 1): registration, email verification, password reset, Sanctum session and token sign-in, four staff roles plus affiliate and customer, append-only audit log. [Report](docs/phase-1-report.md)
- **Affiliate membership** (Phase 2): applications with duplicate warnings, staff review, suspend, deactivate and reactivate, versioned terms, member self-service and notifications. [Report](docs/phase-2-report.md)
- **Store and referral tracking** (Phase 3): catalogue, coupons, checkout with idempotency, referral links and codes, bot filtering, last-click attribution with a 30-day window, manual attribution. [Report](docs/phase-3-report.md)
- **Commission accounting** (Phase 4): rules with precedence and plans, an append-only ledger, a holding period with automatic release, holds, two-person adjustments, nightly reconciliation. [Report](docs/phase-4-report.md)
- **Payouts** (Phase 5): encrypted payout details, requests that reserve the balance, finance approval, manual transfer confirmation with a unique reference. [Report](docs/phase-5-report.md)
- **Dashboards and reports** (Phase 6): affiliate, staff and finance dashboards; 12 staff and 4 affiliate reports with CSV and Excel export. [Report](docs/phase-6-report.md)
- **Payments and fulfilment** (Phase 7): eSewa and Khalti, confirmed only by the provider's status API; review queue; disputes; delivery steps; receipts. [Report](docs/phase-7-report.md)
- **Security and performance** (Phase 8): security review and fixes, concurrency and load tests, indexes, queue reliability, encrypted backups with a tested restore. [Report](docs/phase-8-report.md)
- **Release preparation** (Phase 9): deployment and operations guides, Docker Compose production stack, user guides, staff account commands, release-readiness assessment. [Report](docs/phase-9-report.md)

### Database changes

All 14 migrations create tables or add indexes; none changes or removes existing data. See [docs/migrations.md](docs/migrations.md).
