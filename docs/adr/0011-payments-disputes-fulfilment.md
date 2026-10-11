# ADR 0011: Online payments, disputes and delivery

Status: accepted (Phase 7)

## Context

The master prompt asks for these:

- Real store integration covering the catalog, checkout, the order lifecycle, payment events, refunds, chargebacks and shipping (sections 10 and 25).
- eSewa and Khalti adapters with a sandbox mode and server-side validation.
- No trust in browser redirects. Signatures must be verified, and payment must be confirmed with the provider.
- Idempotency, retried webhooks and out-of-order events must all be handled.
- A `webhook_events` record, and jobs that retry recoverable failures (section 24).
- Disputes go through a controlled review.
- Customer payments are kept separate from affiliate payouts.

Two facts shaped the design:

- Neither eSewa ePay v2 nor Khalti KPG-2 sends a signed server-to-server webhook. Both send the customer's browser back with query parameters. eSewa's are HMAC-signed; Khalti's are not signed at all.
- Both providers offer a server-side status API: eSewa `transaction/status`, Khalti `epayment/lookup`. That API is the only trustworthy source.

## Decision

### The status API is the only proof of payment

- A `PaymentGateway` interface has one adapter per provider (`EsewaGateway`, `KhaltiGateway`). Each adapter does three things:
  - `start()` registers the attempt with the provider and returns where to send the browser.
  - `readCallback()` parses and, where possible, verifies the redirect.
  - `check()` asks the status API.
- A redirect, signed or not, never marks an order paid. `ProviderPaymentService::handleCallback()` records the redirect and then calls `check()`. An order becomes paid only when the status API reports it complete (eSewa `COMPLETE`, Khalti `Completed`) for the expected amount.
- An eSewa redirect with a bad signature is recorded as `rejected`, and the provider is not even asked. Khalti redirects are unsigned, so they are only a hint to check.

### Attempts, events and idempotency

- `payment_attempts`: one row per "Pay with ..." click.
  - The provider reference is unique per provider: eSewa `transaction_uuid` (the attempt's ULID) or Khalti `pidx`.
  - Statuses: initiated, pending, completed, failed, needs_review.
- `webhook_events`: every redirect is stored, keyed by a SHA-256 of its content, with `times_received`, the signature result, a payload stripped to known fields, and the outcome. A replayed redirect updates the counter and triggers another status check. It can never record a second payment.
- Confirming a payment locks the attempt and then the order.
  - `order_payments.payment_attempt_id` and `(provider, reference)` are unique, so concurrent redirects, the scheduled re-check and a staff "check now" produce one payment.
  - `PaymentRecorder` is shared with manual confirmation. It marks the order paid, records the referral event, writes the audit entry, creates commission and queues the receipt email after commit.
- Completed and needs-review attempts are final. A failure redirect arriving after the payment was confirmed (out of order) changes nothing.

### Re-checks and giving up

- `payments:recheck` runs every five minutes. It checks open attempts whose `next_check_at` has passed, backing off after 2, 5, 15, 30, 60, 180 and 360 minutes.
- If the provider cannot be reached, whether through a timeout, a refused connection or an HTTP error, the attempt stays open and is checked again later.
- After 48 hours (`payments.give_up_after_hours`):
  - An attempt the provider never heard of fails.
  - An attempt still pending goes to review.

### Needs-review queue

These cases never change the order automatically. They notify every user with `payments.confirm` by email and in the app:

- the provider reports a different amount
- the order was already paid another way, or was cancelled (the extra payment must be refunded through the provider)
- the provider transaction id is already recorded on another payment
- the payment is still pending after 48 hours
- the provider reports a refund the order does not have

Staff see the queue on the finance dashboard and on the order. They mark each case as dealt with, with a note, after acting in the merchant portal.

### Sandbox and production

- `config/payments.php` holds each provider's switch, mode, keys and sandbox or production URLs. Everything is off by default.
- Sandbox mode is refused when `APP_ENV=production`, so test keys cannot take real orders.
- eSewa's UAT key is public. Khalti's sandbox key and both production keys come from the merchant and live only in `.env`.

### Guest access to an order

- Placing an order returns a random 48-character `meta.access_token`, once; only its SHA-256 is stored.
- The browser keeps it in session storage to start a payment and poll its status. A wrong or missing token returns 404, the same as an unknown order.

### Refunds and disputes

- Neither provider's refund API is used. Refunds are made in the merchant portal and then recorded on the order with the existing refund screen, which reverses commission proportionally (ADR 0008).
- A provider-reported refund that was not recorded goes to review.
- Disputes and chargebacks are recorded by finance (`refunds.record`). Only one dispute can be open per order.
  - Opening one holds the order's pending and approved commissions.
  - Won: the hold is released.
  - Lost: a refund named `DISPUTE-<case reference>` is recorded through `RefundService`. It is spread over the lines in proportion, with delivery last. Commission is reversed the normal way, and the holds are released.

### Delivery

- `fulfilment_status` (unfulfilled, processing, shipped, delivered) is separate from the payment status, because cash-on-delivery orders ship before they are paid.
- It only moves forward. Shipping requires a courier and emails the customer the courier and tracking number.
- The courier is entered by hand; there is no courier API.

## Consequences

- A customer who closes the browser before returning is still confirmed by the scheduled re-check, and gets a receipt email.
- The real sandboxes were not reachable from the build environment, so the adapters are tested against the providers' documented responses and signature vectors. A run against the live sandboxes is still needed before going live.
- Customer payments (this ADR) and affiliate payouts (ADR 0009) share no tables or code paths.
