# ADR 0002: Default attribution policy is last click, coupon overrides

Status: accepted (implemented in Phase 3)

## Decision

- **Last click** within a **30-day** window (configurable) gets the order.
- A valid affiliate coupon entered at checkout **overrides** a stored click, because it is a deliberate action by the customer.
- Exactly one affiliate is credited per order. There is no split allocation in v1.
- Staff can attribute an order manually only with the `attribution.manual` permission and a recorded reason.
- The policy name is stored on every attribution record, so a later policy change never rewrites history.

## Why

Last click is easy to explain to affiliates and matches common practice. A coupon is stronger evidence of influence than an earlier cookie. One affiliate per order rules out duplicate commissions on the same sale.
