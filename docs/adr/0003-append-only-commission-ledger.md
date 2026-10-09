# ADR 0003: Balances come from an append-only commission ledger

Status: accepted (implemented in Phase 4)

## Decision

There is no editable "balance" column. `commission_ledger_entries` is append-only. Each entry moves an amount between buckets (`pending`, `approved`, `available`, `reserved`, `paid`, `reversed`), with a unique idempotency key. Balances are sums over entries. Corrections are compensating entries with a reason, actor and approval. A scheduled reconciliation job re-derives balances and flags differences.

## Why

Nobody, including administrators, can silently change what an affiliate is owed. Every number on a dashboard can be traced to entries, and duplicate events cannot double-count because of the unique keys.
