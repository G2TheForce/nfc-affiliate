# Load and concurrency tests

Neither test runs in CI. Both need MySQL and a scratch database, which they wipe.

## Concurrency (`tests/Concurrency`)

The test starts `php artisan serve` with 8 workers on MySQL. It fires the same money action 8 times at once with `curl_multi`, then checks the database:

| Race | Expected | Result 2026-10-12 |
|---|---|---|
| Same checkout, same `Idempotency-Key` | 1 order; every answer names it | 1 × 201, 7 × 200 replays, 1 order |
| Single-use coupon, 8 different checkouts | 1 redemption | **4 redemptions before the fix**, 1 after (passed 3 runs in a row) |
| Staff confirming one order's payment 8 times | 1 payment, 1 commission | 1 × 201, 7 × 422 |
| 8 full refunds of one line | refunded once | 1 × 201, 7 × 422; commission fully reversed once |
| Affiliate requesting a payout 8 times | 1 payout; balance reserved once | 1 × 201, 7 × 422 |
| `affiliate:mature-commissions` run 3 times at once over 20 due commissions | each approved and released once | 20 available; no duplicate ledger moves; reconciliation clean |

Cause of the coupon bug: the checkout read the coupon before locking it. That first read fixed the transaction's MySQL (REPEATABLE READ) snapshot, so after waiting for the lock, the usage count could not see redemptions other checkouts had just committed. The limit counts now use a locking read. All other money transactions take their row lock as their first statement, and the test above covers them.

```bash
# needs an empty scratch database the DB user can drop and create tables in
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=affiliate_concurrency DB_USERNAME=... DB_PASSWORD=... \
  php artisan test tests/Concurrency
```

## Load (`tests/Load`)

| Script | What it does |
|---|---|
| `seed.php` | Deterministic bulk insert covering 12 months: affiliates (60% approved), links, coupons, clicks (70% unique), orders with 1 to 3 items, payments, refunds, commissions with ledger entries, paid payouts and payment attempts. Sizes come from `AFFILIATES`, `CLICKS` and `ORDERS`. |
| `bench.php` | Calls every dashboard, list and report endpoint in-process with lazy loading disabled. Prints status, query count and time. `ONLY=<text>` filters endpoints; `SHOWQ=5` prints the slowest queries; `DUPS=1` prints repeated ones. |
| `commands.php` | Runs the scheduled commands and prints query counts and time. Run it last, because maturation and pruning change the data. |

The scripts refuse any database whose name does not end in `_test` or `_load`.

```bash
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=affiliate_load DB_USERNAME=... DB_PASSWORD=... CACHE_STORE=array
php artisan migrate:fresh --force
AFFILIATES=1000 CLICKS=500000 ORDERS=50000 php -d memory_limit=6G tests/Load/seed.php   # about 80 s
php tests/Load/bench.php > /dev/null; php tests/Load/bench.php                          # warm up, then measure
php -d memory_limit=2G tests/Load/commands.php
```

### Results at 1,000 affiliates, 500k clicks and 50k orders

This is about ten times the launch target of 100 affiliates. Timings vary by about 30% between runs on the same machine.

| Endpoint (one-year range unless noted) | Before Phase 8 | After |
|---|---|---|
| Staff dashboard, 30 days | 2.1 s | 0.9 s |
| Staff dashboard, one year | 10.5–16.8 s | 5.0 s |
| Referral summary | 7.8 s | 0.85 s |
| Affiliate performance report | 5.7 s | 1.1 s |
| Commission accruals report | 346 ms | 7 ms |
| Finance dashboard | 0.96–1.15 s | 0.6–1.0 s |
| Order list by status | 14 ms | 25 ms (no change) |
| Affiliate dashboard (busiest affiliate) | 10–50 ms | 45–90 ms (no change) |
| `affiliate:prune-referral-data` (376k clicks) | 19 s in one transaction | 18 s in 100 short batches |
| `affiliate:mature-commissions` (2,524 due) | 28 s | 28 s (not changed; about 9 queries per commission, hourly) |
| `affiliate:reconcile-commissions` | 6.0 s, 44 MB | unchanged |

The staff dashboard over a full year still takes 5 s at this volume. It runs about 27 grouped queries over every paid order in that year. At launch volume it takes about 0.6 s.

Known scaling limits, for later:

- Ledger balances and the finance dashboard sum the whole ledger. That costs 0.4–0.8 s at 200k entries. Balance snapshots would fix it when needed.
- Reconciliation loads adjustment and payout ledger rows into memory: 44 MB at 200k entries, growing linearly.
- Maturation makes about 9 queries per commission. Approving and releasing under a single lock would save about a third of them.
