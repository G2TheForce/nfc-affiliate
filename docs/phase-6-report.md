# Phase 6 report: Dashboards and reports

Date: 2026-10-11 · Status: **complete, verified in a test environment**

## What was built

| Item | Where |
|---|---|
| Affiliate dashboard with these parts: <ul><li>clicks, unique clicks, attributed orders, conversion</li><li>sales, commissionable sales, commission earned, refunded</li><li>current pending, approved, available, in-payout, paid and reversed balances</li><li>charts of clicks and orders, commission, sales and conversion</li><li>products sold, coupon codes and referral links</li><li>downloads of the member's own records</li></ul> | `/account/affiliate/dashboard`, `GET /api/v1/affiliate/dashboard` |
| Staff dashboard with these parts: <ul><li>affiliate counts and applications</li><li>referral sales, commission owed by stage, payout queue</li><li>charts</li><li>top affiliates, top products and coupon campaigns</li><li>recent audit and security events</li><li>filters by period, affiliate, product and coupon</li></ul> | `/admin`, `GET /api/v1/admin/dashboard` |
| Finance dashboard with these parts: <ul><li>total owed, available, in payout, in holding, on fraud hold</li><li>paid and reversed, all time</li><li>refunds and payouts in the period</li><li>payout queue and reconciliation status</li><li>ledger chart and ledger movements</li><li>largest balances owed</li></ul> | `/admin/finance`, `GET /api/v1/admin/finance/dashboard` |
| 12 staff reports (preview plus CSV and Excel export) and 4 affiliate reports | `/admin/reports`, [ADR 0010](adr/0010-reporting.md) |
| Streamed CSV (UTF-8 with BOM) and XLSX exports, with protection against formula injection. They are permission-checked, audited and limited to 10 per minute | `ReportExporter` |
| One definition for every figure, shown on each dashboard under "How these numbers are counted" | `Metrics::DEFINITIONS` |
| Email and in-app notice to reconciliation staff when drift is found | `ReconciliationDriftDetected` |

The staff reports cover:

- registrations and approval rates
- clicks and conversions by affiliate
- retention
- product and campaign (coupon) performance
- commission accruals and reversals
- balances owed (payout liability)
- payouts and outstanding payout requests
- refunds
- fraud review outcomes

Periods are Nepal calendar dates, up to one year. Charts are daily up to 92 days, otherwise monthly. The chosen period and filters are kept in the page address, so a view can be bookmarked or shared.

## Verification (actual results)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | 263 passed (17 new) |
| `php artisan test` on MySQL 8.0.46 | 263 passed |
| `vendor/bin/pint --test`, permissions matrix check | pass |
| `composer audit`, `npm audit --omit=dev` | no advisories |
| `npm run typecheck`, `npm run lint`, `npm run build` | pass |
| OpenAPI (Redocly lint) | valid (warnings only, as before) |
| Browser test (Chromium, MySQL, dev seed) | passed (below) |

### Figures checked against the database

The tests use a fixed scenario:

- two affiliates and two products
- one coupon
- clicks with repeats
- a paid order, an unpaid order, a coupon order and another affiliate's order
- a NPR 1,000 refund

Every dashboard total is asserted against the expected numbers and against direct queries on `referral_clicks`, `commissions` and the ledger. The tests also check:

- The chart series add up to the totals, and products add up to sales.
- Balances equal `CommissionLedger::balances()`, the figure reconciliation uses.
- The finance flows match the ledger entry sums.
- Days follow Nepal time: a click at 23:59 Nepal time on 1 September counts on 1 September, not the 2nd. Ranges over 92 days switch to months, and ranges over a year are refused.

### Browser test

The browser test ran on the dev data after a real payout flow:

- a coupon sale of NPR 3,150
- commission of NPR 315
- a NPR 1,500 approved bonus
- NPR 1,815 paid out

It checked these, on desktop and at 360px phone width:

- The affiliate dashboard showed NPR 3,150 sales and NPR 315 commission. The balance showed NPR 1,815 paid and NPR 0 available, matching the ledger.
- The chart tooltip followed the pointer.
- The 7-day preset updated the address.
- "My commissions" downloaded as CSV, with the right columns and amounts in rupees.
- The staff dashboard showed the same totals. The affiliate filter worked, with search by name.
- The finance page showed the ledger movements: NPR 315 accrued, NPR 1,500 manual credit, NPR 1,815 moved to payout and NPR 1,815 paid out. Reconciliation was clean.
- The payouts report previewed and downloaded as Excel.
- An affiliate manager saw only the reports their role allows, and no export buttons.
- No page scrolled sideways at 360px. Wide tables scroll inside their card.

### New tests

- Dashboard figures for affiliate, staff and finance, each matched to the database.
- An affiliate cannot widen their own dashboard with an `affiliate_id`.
- Filters by affiliate, product and coupon; unknown filter ids are refused.
- Nepal-day boundaries, monthly buckets and the one-year limit.
- Report access per permission. Export needs `reports.export`, and affiliate managers and support cannot see refunds.
- CSV and XLSX contents, including formula-injection guarding and money in rupees.
- The export audit entry.
- The fraud review report lists holds and blocked attributions; previews are capped at 100 rows and say so.
- Affiliate exports contain only the member's own rows, with no customer details.
- Report totals reconcile with the ledger.
- Drift notifications reach reconciliation staff.
- Filter options: search, and keeping the chosen affiliate in the list.

## Not done yet / known limitations

- **Chargebacks:** no payment provider sends chargebacks yet, so the refunds report covers reversed payments. A chargeback column comes with a provider integration.
- **Queued exports:** exports stream directly. If they grow past what one request can stream, a queued export with a download link can be added.
- **Dark mode:** the app has a light theme only. Charts use theme colours, so a dark theme would need its own validated chart colours.
- **Open business decisions from earlier phases:**
  - default commission rates
  - VAT in the commission base
  - automatic release after the holding period
  - TDS on payouts
  - the NPR 1,000 payout minimum
  - a separate payer
