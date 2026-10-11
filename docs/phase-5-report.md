# Phase 5 report: Payouts

Date: 2026-10-11 · Status: **complete, verified in a test environment**

## What was built

| Item | Where |
|---|---|
| Payout details: bank transfer, eSewa or Khalti, stored encrypted and shown masked. Changes need the password and send a security email | `PayoutMethodService`, `PUT /api/v1/affiliate/payout-method` |
| Payout request for the whole available balance. Checks: active membership, saved details, no open payout, at least NPR 1,000. Funds are reserved in the same transaction | `PayoutService::request`, `POST /affiliate/payouts` |
| One open payout per affiliate, enforced by a row lock and a unique database key | `payouts.open_affiliate_profile_id` |
| Finance review: approve, or reject with a reason. Rejection returns the funds | `/api/v1/admin/payouts/{payout}/approve`, `/reject` |
| Manual payment confirmation with a transfer reference unique per method. Covered commissions become paid | `/admin/payouts/{payout}/mark-paid` |
| Failed transfer: the funds return to the available balance | `/admin/payouts/{payout}/mark-failed` |
| Affiliate can cancel before review | `/affiliate/payouts/{payout}/cancel` |
| Optional separation of duties: the approver cannot also confirm payment | `AFFILIATE_PAYOUT_REQUIRE_SEPARATE_PAYER` (default off) |
| Payout-level ledger entries (reserve, paid, release) with idempotency keys | [ADR 0009](adr/0009-payouts.md) |
| Notifications: the affiliate is emailed and notified in-app at each step (reference on payment, reason on rejection or failure); finance gets an in-app notice per request | `PayoutStatusChanged`, `NewPayoutRequest`, `PayoutMethodChanged` |
| Audit of every step, plus each view of full account details | `payout.*`, `payout.destination_viewed`, `payout_method.saved` |
| Reconciliation now covers payouts, paid commissions and each affiliate's available balance | `ReconciliationService` |
| Affiliate screen: balances, request, cancel, payout details, history | `/account/payouts` |
| Finance screen: queue with open requests first, totals by status, detail with covered commissions and ledger, and actions | `/admin/payouts` |

No payout provider is integrated. Finance makes the transfer outside the system and records its reference; nothing is marked paid automatically.

## Verification (actual results)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | 246 passed (21 new) |
| `php artisan test` on MySQL 8.0.46 | 246 passed |
| `vendor/bin/pint --test`, permissions matrix check | pass |
| `composer audit`, `npm audit --omit=dev` | no advisories |
| `npm run typecheck`, `npm run lint`, `npm run build` | pass |
| OpenAPI (Redocly lint) | valid (warnings only, as before) |
| Browser test (Chromium, MySQL, dev seed) | passed (below) |

The browser test walked the payout flow end to end:

1. A guest bought a metal card with the affiliate coupon, and finance confirmed the payment. The commission of NPR 315 matured; the dev server ran with a zero-day holding period.
2. Finance proposed a NPR 1,500 bonus and a second person approved it. Available balance: NPR 1,815.
3. On a phone-width screen, the affiliate added bank details:
   - A wrong password was refused.
   - The details showed as "Nabil Bank ••••8901".
4. The affiliate requested NPR 1,815. The page then showed NPR 0 available and NPR 1,815 in progress, with no horizontal scrolling.
5. The finance manager saw the request in the queue, with the masked destination only, and approved it.
   - After approval, the full account number appeared for the transfer.
   - A malformed reference was refused.
   - Confirming with a reference marked the payout paid.
6. On-demand reconciliation was clean.
7. The affiliate's history showed the payout as paid with the reference.
8. Once the queue worker ran, the notifications were delivered:
   - to the affiliate: request received, approved, sent, details changed
   - to finance: new request

### New security and integrity tests

- **Full flows:**
  - Request, approve, pay: balances, commission status, notifications, audit trail, clean reconciliation.
  - Reject, fail and cancel each return the funds.
  - A rejected commission is covered again by the next request.
- **Eligibility:**
  - No details, a balance below the minimum, or pending commission blocks a request.
  - A second open request is refused.
  - A suspended affiliate cannot request, and cannot be approved or paid.
- **Duplicate prevention:**
  - Approving twice, paying twice, or paying from a stale copy is refused, with exactly two ledger entries.
  - Reusing a reference on another payout is refused.
  - The database rejects a second open payout.
- **Separation of duties:** when it is on, the approver cannot confirm payment, and the screen hides the button.
- **Refunds:**
  - A refund while a payout is open, or after payment, claws back from available.
  - The reversed commission is not marked paid.
  - Reconciliation stays clean.
- **Adjustments** are included in the payout amount.
- **Payout details:**
  - The account number is encrypted at rest and absent from the audit log.
  - The password is required, the format is validated, and the security notice is sent.
  - A change after a request does not redirect that payout.
- **Access:**
  - Full details are returned only to staff who can mark payouts paid, only while the payout is approved, and each view is audited.
  - Affiliate managers can view the queue but not account numbers or actions.
  - Support and customers are refused.
  - Affiliates see only their own payouts and cannot cancel someone else's.
- **Immutability:** the payout amount and destination cannot be changed through the model.
- **Reconciliation** flags a stray payout ledger entry as both a payout mismatch and an available-balance mismatch.

## Not done yet / known limitations

- **Payout providers:** there are no bank or wallet APIs for sending money. Payment is manual with a recorded reference. A provider would replace the mark-paid step with a verified callback.
- **Returned transfers after payment:** a paid payout is final. If a bank later returns the money, finance records a positive adjustment with the bank's note.
- **Tax:** no TDS is withheld from payouts. A Nepal tax advisor should confirm whether, and how much.
- **Schedule:** payouts are on request only. A monthly batch or automatic requests can be added if wanted.
- **Queue worker:** notifications are queued. Production needs `php artisan queue:work` running under a process manager; this goes into the Phase 9 deployment guide.
- **Business decisions to confirm:**
  - The NPR 1,000 minimum.
  - Whether to require a second finance person to confirm payments. It is off by default.
