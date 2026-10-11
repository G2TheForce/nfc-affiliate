# Phase 4 report: Commission accounting

Date: 2026-10-11 · Status: **complete, verified in a test environment**

## What was built

| Item | Where |
|---|---|
| Commission plans and rules (global, plan, category, product, affiliate; percent, fixed per unit, exclude) with versioning and overlap rejection | `CommissionRuleService`, `/api/v1/admin/commission-rules`, `/admin/commission-plans` |
| Deterministic precedence: exclusion, then affiliate, product, category, plan, global | `RuleResolver`, [ADR 0008](adr/0008-commission-accounting.md) |
| Integer-only calculation, half-up per line, fixed amounts capped at the line | `CommissionMath` |
| Staff-verified payment confirmation with a unique reference: the only way an order becomes paid | `ManualPaymentService`, `POST /admin/orders/{order}/payments` |
| Refunds by line and delivery charge, with a unique reference | `RefundService`, `POST /admin/orders/{order}/refunds` |
| One commission per order line, with a full calculation snapshot, created in the payment transaction | `CommissionService::createForOrder` |
| Refund reversals: only the refunded share, recomputed on the remaining base, with no rounding drift | `CommissionService::reverseForRefund` |
| Lifecycle: pending, then approved and available after the 14-day holding period. Holds, voids, and optional manual release | `CommissionLifecycle`, `affiliate:mature-commissions` (hourly) |
| Append-only ledger with idempotency keys; balances are sums | `CommissionLedger`, `commission_ledger_entries` |
| Manual adjustments: maker-checker, so the proposer cannot approve | `AdjustmentService`, `/admin/adjustments` |
| Nightly reconciliation that records drift and never auto-corrects | `ReconciliationService`, `affiliate:reconcile-commissions` (03:30 Nepal time) |
| Affiliate earnings: balances, commissions (masked order numbers), statement, current rates; email and in-app notice when commission becomes available | `/api/v1/affiliate/earnings/*`, `/account/earnings` |
| Staff screens: commissions (liability, filters, hold, void, release), rules and plans, adjustments, reconciliation, order payment and refund panels, plan and balances on the affiliate profile | `resources/js/pages/admin` |
| New permissions: `payments.confirm`, `refunds.record`, `commissions.approve` (Finance Manager and Super Admin) | [permissions matrix](permissions-matrix.md) |

## Verification (actual results)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | 225 passed (67 new) |
| `php artisan test` on MySQL 8.0.46 | 225 passed |
| `vendor/bin/pint --test`, permissions matrix check | pass |
| `composer audit`, `npm audit` | no advisories |
| `npm run typecheck`, `npm run lint`, `npm run build` | pass |
| OpenAPI (Redocly lint) | valid (warnings only, as before) |
| Browser test (Chromium, MySQL, dev seed) | passed (below) |

The browser test walked the money flow end to end:

1. A guest bought a metal card (NPR 3,500) and a review stand (NPR 1,800) with the affiliate coupon (10% off).
2. The finance manager confirmed the bank transfer with its reference. The order became Paid. Two pending commissions appeared:
   - NPR 315: 10% of NPR 3,150, under the global rule.
   - NPR 150: the fixed review-stand rule.
3. Finance refunded NPR 810 of the stand line. The order became Partly refunded, and exactly NPR 75 of commission was reversed. An over-refund was refused with "can be at most NPR 810.00".
4. Programme liability showed NPR 390 pending and NPR 75 reversed. On-demand reconciliation was clean.
5. On a phone-width screen, the affiliate saw NPR 390 pending, "next available 25 Oct 2026", masked order numbers, their per-product rates, and a 3-line statement. There was no horizontal scrolling.
6. A finance user's own adjustment showed no Approve button.
7. The affiliate manager created a product rule. An overlapping second rule was refused with a clear message.

### New security and integrity tests

- Payment confirmation:
  - Replaying it creates nothing new.
  - A second payment, a reused reference, a wrong amount or a cancelled order is refused.
  - Only finance and super admin can confirm.
- Duplicate protection:
  - The unique (line, affiliate) key and the ledger idempotency keys stop duplicate commissions and duplicate reversals.
  - The same refund reference is applied once.
- Edge cases:
  - Unattributed orders, non-earning coupons and orders with no matching rule earn nothing.
  - An affiliate suspended before payment gets a held commission.
- Precedence:
  - Each level beats those below it.
  - Exclusions beat overrides.
  - Rules in force at placement are used.
  - Rule edits never change existing commissions.
- Refunds:
  - Only the refunded line is reversed.
  - Three one-third refunds reverse exactly the whole commission.
  - A refund after availability reverses from available.
  - A refund after payout leaves a negative, recoverable balance.
  - A voided commission is not reversed twice.
  - A delivery-only refund leaves commission alone.
- Lifecycle:
  - Nothing matures early.
  - Maturation runs once and notifies once.
  - Holds block maturation.
  - Void works only before availability.
  - Finance and support cannot hold or void.
- Adjustments:
  - The proposer cannot approve.
  - Rejected adjustments never reach the ledger.
  - Adjustments cannot be edited.
- Immutability: ledger entries, payments, refunds and commission calculation fields cannot be updated or deleted through the models.
- Reconciliation: clean after real activity. A raw database insert into the ledger is flagged as drift and audited.
- Affiliate views:
  - Affiliates see only their own earnings, with masked order numbers and no staff fields.
  - Suspended members still see earnings but not rates.
- Order detail shows commissions to finance but not to support.
- Manual attribution of a paid order creates the commission. It is locked once commission exists.

## Not done yet / known limitations

- **Payouts** (reserving the available balance, finance review, marking paid) are Phase 5. Until then, nothing reaches the reserved or paid buckets.
- **Payment gateways**: eSewa, Khalti and Fonepay webhooks with signature checks are Phase 7. Today, staff confirm payments they verified themselves.
- **Campaign overrides** (precedence step 2), tiered and recurring commissions are not built. Multi-level commissions stay disabled.
- **Tax**: prices are treated as tax-inclusive because the store does not record VAT separately. A Nepal tax advisor should confirm whether VAT must be excluded from the commission base, and how TDS applies to payouts.
- **Chargebacks and disputes** need provider data (Phase 7). For now, staff can hold or void a commission, or record a refund.
- **Database grants**: the production database user should not have UPDATE or DELETE on `commission_ledger_entries`. This goes into the deployment guide in Phase 9.
- Business decisions to confirm:
  - The actual commission rates. Production starts with no rules, so no commission is earned until a rule exists.
  - Whether auto-release should stay on.
  - The 14-day holding period.
