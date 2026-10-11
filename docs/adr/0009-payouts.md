# ADR 0009: Payouts

Status: accepted (Phase 5)

## Context

The master prompt (section 14) asks for this payout flow:

1. The affiliate requests a payout.
2. The backend checks the balance and eligibility, and reserves the funds atomically.
3. Authorised finance staff review it, and duplicate approval or payment is prevented.
4. Payment is made through a configured provider or a verified manual process, and is recorded with a unique reference.
5. Failed or rejected payouts release the funds.
6. The affiliate is notified, and everything is audited.

No payout provider is integrated. eSewa, Khalti and bank APIs for sending money are not part of this phase, and the prompt forbids fake providers or invented confirmations.

## Decision

### What is paid

- A request covers the affiliate's **whole available balance** at that moment. There is no partial amount.
  - Commissions are either paid or not, so a commission is never split across payouts.
  - The amount equals the ledger's available balance. That balance includes approved adjustments and any earlier refund clawback.
- The request records which `available` commissions it covers (`payout_items`, with each net amount). When the payout is paid, those commissions become `paid`.
- Eligibility:
  - an approved (active) membership
  - saved payout details
  - no other open payout
  - an available balance of at least `AFFILIATE_PAYOUT_MINIMUM_MINOR` (NPR 1,000)
  - Pending, approved and held commission does not count.
- One open payout per affiliate is enforced twice:
  - The request locks the affiliate row.
  - `payouts.open_affiliate_profile_id` is unique and set only while the payout is open.

### Lifecycle

```
requested --approve--> approved --mark paid (reference)--> paid
requested --cancel (affiliate)--> cancelled
requested | approved --reject (note)--> rejected
approved --mark failed (reason)--> failed
```

- `payouts.review` approves and rejects. `payouts.mark_paid` confirms the transfer or records that it failed. By default, Finance Manager and Super Admin hold both.
- **Separation of duties** is controlled by `AFFILIATE_PAYOUT_REQUIRE_SEPARATE_PAYER` (default false). When it is on, the approver cannot also confirm the payment. It is off by default because a small team may have one finance person; turn it on once there are two.
- Approving or paying is refused while the affiliate is suspended or deactivated. Finance can still reject.
- **Manual payment only.** Finance makes the transfer from the company bank or wallet, then enters the transfer reference. The system never claims money was sent on its own.
  - A reference can settle only one payout per method. This is a unique (method, reference) key, plus a friendly check before it.
  - A paid payout is final. If a bank later returns money that was already marked paid, finance records a positive adjustment with the bank's note. Automatic handling of returned transfers waits for a provider integration.

### Ledger (extends ADR 0003 and ADR 0008)

Payout entries are posted at the payout level (`commission_ledger_entries.payout_id`), not per commission:

| Step | Entry | Idempotency key |
|---|---|---|
| request | available → reserved | `payout:{id}:reserve` |
| paid | reserved → paid | `payout:{id}:paid` |
| reject, fail or cancel | reserved → available | `payout:{id}:release` |

- A commission's own entries therefore still net into `available` after it is paid. Its `paid` status, plus the paid payout that covers it, records that it was paid out.
- A refund while a payout is open, or after it is paid, reverses from `available`, as ADR 0008 already did for paid commissions. The payout amount does not change, and the shortfall shows as a negative available balance. That balance is recovered from future commission before the next payout can be requested.
- Each state change locks the payout row and checks the current status inside the transaction. The idempotency key stops a second ledger entry even if two requests race.

### Payout details

- One payout method per affiliate: bank transfer (bank, branch, account number), eSewa or Khalti (10-digit registered mobile number).
- Details are stored with Laravel's `encrypted:array` cast, keyed by `APP_KEY`. Screens, notifications and the audit log use only a masked form, such as "Nabil Bank ••••1234".
- Changing details requires the account password and sends a security email. A payout keeps a copy of the details it was requested with, so a later change cannot redirect money that is already in progress.
- Full details are returned only to `payouts.mark_paid` holders, only while the payout is approved (the moment the transfer is made). Each view is audited as `payout.destination_viewed`.

### Notifications and audit

- The affiliate gets email and in-app notices on request, approval, payment (with the reference), rejection and failure (with the reason).
- Staff with `payouts.review` get an in-app notice for each new request.
- Audit actions:
  - `payout.requested`, `payout.approved`, `payout.rejected`, `payout.paid`, `payout.failed`, `payout.cancelled`
  - `payout.destination_viewed`
  - `payout_method.saved` (masked values only)

### Reconciliation

`ReconciliationService` now also checks:

- Each payout's entries: the amount sits in reserved while open, in paid once paid, and nowhere after a release.
- The open flag matches the status.
- Every paid commission is covered by a paid payout. A covered commission is paid, or reversed if its order was refunded before payment.
- Per affiliate, the available balance re-derived from records equals the ledger: the net of available and paid commissions, plus approved adjustments, minus open and paid payouts.
- Entries linked to nothing at all (no commission, adjustment or payout) are still flagged.

## Consequences

- Finance works from one queue (`/admin/payouts`). Affiliates request and track payouts at `/account/payouts`.
- A provider integration (Phase 7 or later) can replace the manual mark-paid step with a verified provider callback, without changing the ledger model.
- **Open business questions:**
  - Whether TDS must be withheld from affiliate payouts, and how. This needs a Nepal tax advisor; nothing is withheld today.
  - Whether a regular payout schedule (for example monthly) is wanted, instead of on-demand requests.
