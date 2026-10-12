# Changelog

## 1.1.0 (Unreleased)

Closes the main specification gaps left in 1.0.0 (Phase 10). [Report](docs/phase-10-report.md)

- **Affiliate notices**: an email and in-app notice when a referred order is paid (masked order number, commission and when it becomes payable, or that no commission applies), and when commission is reversed by a refund or cancelled by staff (never with the staff reason). Everyone gets a "password changed" email after a password reset.
- **Fraud signals and review queue**: a nightly check (04:00 Nepal time) for unusually high conversion, many clicks with no orders, high refund rates and sudden earnings jumps, and a check on each payout request for large amounts and recent payout-detail changes. Thresholds are set in `.env` (`AFFILIATE_FRAUD_*`). Flags go to a **Fraud review** screen and show on the payout screen; they never block or hold anything by themselves.
- **Public programme page**: `/affiliates` ("Earn with us" in the store menu) explains the programme from the live settings, the global rate and the current terms.
- **Campaigns**: dated promotions for chosen products and affiliates, with their own commission rule (after exclusions, before affiliate overrides). Orders join a campaign through a campaign tracking link or a campaign coupon. Staff see orders, sales and commission against an informational budget. Affiliates see open campaigns and make campaign links on **Referral links**.
- **Marketing materials**: staff upload images, MP4 videos, PDFs, ZIP files (up to 20 MB) or post text; affiliates browse, download and copy them. File types are checked from the content, SVG is refused, and files are served only through the API.
- API spec version 1.1.0 with the new endpoints. Upload limits raised in `docker/php.ini` and `docker/nginx.conf`.

### Database changes

Three new migrations create `fraud_flags`, the campaign tables and `marketing_assets`, and add a nullable `campaign_id` to links, coupons, attributions and commission rules. None changes or removes existing data. See [docs/migrations.md](docs/migrations.md).

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
