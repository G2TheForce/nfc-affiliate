# ADR 0001: Money is stored as integer minor units

Status: accepted (applies from Phase 3/4, recorded now)

## Decision

All monetary amounts are `BIGINT` columns holding minor units (paisa for NPR, 1 NPR = 100 paisa), always next to a `CHAR(3)` currency code. Percentage rates are stored as basis points (10.00% = 1000). Calculations use integer arithmetic. Rounding happens once per commission line, half-up to the nearest minor unit. Binary floating point is never used for money.

## Why

Floats cannot represent most decimal fractions exactly, so totals drift and cannot be reconciled. Integer minor units are exact, fast, and portable across MySQL, PHP and JavaScript (amounts stay well under 2^53).

## Consequences

Columns are named `*_minor` (for example `amount_minor`). Display formatting divides by the currency's minor-unit factor from `config('affiliate.currency.minor_units')`.
