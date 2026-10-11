# ADR 0008: Commission rules, lifecycle and accounting

Status: accepted (Phase 4)

## Context

The master prompt asks for a commission engine with deterministic rule precedence, reproducible calculations, an append-only ledger, refund reversals, manual adjustments with approval, and reconciliation. Real payment gateways arrive in Phase 7, but commissions must only ever come from verified payments.

## Decision

### Where money comes from

- An order becomes paid only through an `order_payments` record. Until Phase 7, that record is created by a staff member with `payments.confirm` who has seen the money arrive (bank statement, cash in hand, or the wallet's merchant dashboard) and enters its reference. The browser redirect after checkout never marks anything paid.
- The amount must equal the order total; partial payments are not supported. The provider and reference pair is unique, so the same payment cannot be recorded twice. Repeating the same confirmation returns the existing record.
- A cancelled order cannot be confirmed as paid. Staff refund the customer outside the system instead.
- Refunds are recorded after the money has gone back, with their own unique reference, naming the order lines (and any delivery charge) they cover. Line refunds can never exceed what is left of the line.

### Rules and precedence

Rule scopes: global, plan, category, product, affiliate. Kinds: percent (basis points), fixed (per unit, in a currency), exclude (product and category only). For each order line the first match wins:

1. product exclusion, then category exclusion
2. affiliate override
3. product rate
4. category rate
5. the affiliate's plan default
6. global default

- Two active rules for the same target with overlapping dates are refused when saved, so every step has at most one candidate.
- Rules are evaluated as they stood when the order was **placed** (what the affiliate promoted), with the affiliate's plan at payment time.
- No matching rule means no commission. Production starts with no rules; the business must create at least a global default.
- Campaign overrides (master prompt step 2) are not built yet; they will slot in at step 2 when campaigns exist.
- Tiered, recurring and multi-level commissions are not implemented. Multi-level stays disabled pending legal advice.
- A coupon with `earns_commission = false` produces no commission.

### Calculation

- Base: the line total after its share of the order discount (largest-remainder allocation from checkout). Delivery charges and payment fees are never part of the base. Prices are treated as tax-inclusive because the store does not yet record tax separately; whether VAT should be excluded from the base is an open question for the tax advisor.
- Percent: `round_half_up(base × rate_bp / 10000)`, once per line. Fixed: `fixed × quantity`, capped at the line base so a fixed rule can never pay more than the customer paid.
- Every commission stores a snapshot: the rule (id, version, kind, rate or amount, dates), the line (price, quantity, discount, total), the order (number, currency, placed and paid times, coupon), the attribution source and policy, and the holding period. Editing a rule bumps its version and never changes existing commissions.
- Partial refunds recompute the commission on what remains of the line after all refunds so far; the difference is reversed. This makes repeated partial refunds add up exactly, with no rounding drift.

### Lifecycle

```
pending  --(holding period passed, not held, order still paid)-->  approved  --(auto or finance release)-->  available  --(payout, ADR 0009)-->  paid
pending | approved  --(voided by staff, or fully refunded)-->  reversed
```

- Holding period: `AFFILIATE_HOLDING_PERIOD_DAYS` (14) from payment. `affiliate:mature-commissions` runs hourly.
- `AFFILIATE_COMMISSION_AUTO_RELEASE` (true): approved commissions are released straight away. When false, finance (`commissions.approve`) releases them by hand.
- Holds (`fraud.review`) stop a pending or approved commission from moving on. A commission whose affiliate was not approved at payment time is created on hold.
- Partial reversal is a flag (`reversed_minor > 0`), not a separate state, so a partly refunded commission keeps its place in the lifecycle.
- Void (`fraud.review`) cancels a pending or approved commission with a reason. Available or paid commissions are corrected with an adjustment instead.
- A refund after payout ([ADR 0009](0009-payouts.md)) reverses the commission from the available balance, which may go negative. That negative balance is recovered from future commission; nothing is deleted.

### Ledger and balances (extends ADR 0003)

- Each `commission_ledger_entries` row moves a positive amount from one bucket to another (`pending`, `approved`, `available`, `reserved`, `paid`, `reversed`; NULL means outside the ledger). Balances are sums: incoming minus outgoing.
- Every entry has a unique idempotency key, such as `commission:{id}:accrue`, `refund:{refund}:commission:{id}`, or `adjustment:{id}`. Each step therefore posts once, however often it is retried.
- The models refuse updates and deletes of ledger entries, payments and refunds, and of a commission's calculation fields. In production the database user should also lack UPDATE and DELETE on the ledger table (deployment guide, Phase 9).

### Adjustments

- Proposed with `commissions.adjust`, with a signed amount, a reason and optionally the commission it relates to. Approved or rejected with `commissions.approve` by **someone other than the proposer**. Only approval posts to the ledger: a credit goes from NULL to available, a debit from available to NULL.

### Reconciliation

- `affiliate:reconcile-commissions` runs daily at 03:30 Nepal time, and finance can run it on demand. It re-derives what each commission and adjustment should hold in each bucket and compares that with the ledger. It also flags entries that are not linked to any record.
- Any difference is stored as a drift result, logged as an error and audited. It is never corrected automatically.

## Consequences

- Every displayed number is a sum over ledger entries and can be traced to a payment, refund, commission or approved adjustment.
- Order payment is a manual staff step until gateways are connected. This is deliberate: no unverified source can create commission.
- Manual attribution is fixed once commission exists. Changing who earns on a paid order means voiding the commission and making an adjustment.
