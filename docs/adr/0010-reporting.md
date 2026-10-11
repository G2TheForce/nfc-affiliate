# ADR 0010: Dashboards, reports and exports

Status: accepted (Phase 6)

## Context

The master prompt asks for these:

- An affiliate dashboard (section 12), a staff dashboard (section 13) and reports with CSV and XLSX export (section 20).
- Figures must match the database and reconcile with the accounting records.
- Aggregation must happen in the database, not by loading every row.
- Exports must be restricted by permission, audited, and protected against spreadsheet formula injection.

## Decision

### One place for every figure

`App\Domain\Reporting\Metrics` computes every dashboard number with grouped SQL. Dashboards and reports call it; no screen computes its own totals. Each definition is returned with the data (`definitions`) and shown under "How these numbers are counted":

| Figure | Counted from | Dated by |
|---|---|---|
| Clicks, unique clicks | `referral_clicks` (bots and rapid repeats are never stored) | click time |
| Attributed orders | `order_attributions` joined to `orders`, excluding cancelled | order placed |
| Conversion | attributed orders per 100 unique clicks; empty when there are no unique clicks | |
| Sales (revenue) | `order_items.line_total_minor` of attributed orders (after discounts, before refunds, without delivery) | payment confirmed (`orders.paid_at`) |
| Commissionable sales, commission earned, commission reversed | `commissions` joined to the order | payment confirmed |
| Refunded | `refund_items` product amounts on attributed orders | refund completed |
| Ledger flows (accrued, released, reversed, paid out, adjustments) | `commission_ledger_entries` by type | entry time |
| Balances (pending, approved, available, reserved, paid, reversed) | `CommissionLedger::balances()`, the same sums reconciliation uses | now, never filtered by date |

Earned and reversed commission are therefore tied to the order's payment date: a refund in October on a September sale lowers September's net, and also appears in October's ledger flows and refunds. The ledger figures are the accounting view; the order-dated figures are the performance view.

### Periods and time

- Ranges are Nepal calendar dates, inclusive, at most one year, defaulting to the last 30 days. Days run midnight to midnight Asia/Kathmandu.
- Charts use daily buckets up to 92 days, otherwise monthly. Buckets are made in SQL by shifting UTC timestamps by Nepal's fixed offset (+05:45; Nepal has no daylight saving), so SQLite and MySQL give the same buckets.

### Campaigns are coupons

The store has no separate campaign entity. A "campaign" in dashboards and reports is a coupon. For affiliates, link performance (by named referral link) is shown as well.

### Who sees what

- Affiliates see only their own figures. The profile is taken from the session; any affiliate or filter parameter is ignored.
- Staff dashboard: `reports.view`. Liabilities need `commissions.view`, the payout queue needs `payouts.view` and recent events need `audit_logs.view`.
- Finance dashboard: `payouts.view` or `commissions.view`. The reconciliation status needs `reconciliation.view`.
- Each staff report names its own permission; the report list only shows reports the user may open. Exporting also needs `reports.export`.

### Exports

- Exports are streamed: rows come from a generator and are written straight to the response, as CSV with a UTF-8 byte order mark or XLSX through OpenSpout. Row-level reports (commissions, refunds, payouts, statements) read in chunks with `lazyById` or a cursor. Performance reports are grouped in SQL, one row per affiliate, product or coupon. Memory use does not grow with the number of orders.
- Money is in rupees with two decimals (a number in XLSX); times are Nepal time.
- Text starting with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with `'` so spreadsheets show it as text.
- Every export is audited as `report.exported` with the report, format, period and filters. Exports are limited to 10 per minute per user.
- Affiliate exports never include customer names, emails, phones or addresses; order numbers are partly masked.

### Notifications

When nightly or on-demand reconciliation finds drift, every active user with `reconciliation.view` gets an email and an in-app notice.

## Consequences

- Report totals reconcile with the ledger because they read the same tables with the same sums. Tests check each dashboard figure against direct table queries.
- Chargebacks are not reported separately. No payment provider sends chargebacks yet; a reversed payment is recorded as a refund. A chargeback column can be added when a provider is integrated.
- Very large exports are streamed rather than queued. If exports grow past what a request can stream in time, a queued export with a download link can be added behind the same report classes.
