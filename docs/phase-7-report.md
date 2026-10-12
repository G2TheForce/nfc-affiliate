# Phase 7 report: Store and payment integration

Date: 2026-10-11 · Status: **built and tested against the providers' documented behaviour; a run against the live eSewa and Khalti sandboxes is still needed**

## What was built

| Item | Where |
|---|---|
| eSewa ePay v2 adapter: signed form, success and failure return, status API | `EsewaGateway` |
| Khalti KPG-2 adapter: initiate, return, lookup API | `KhaltiGateway` |
| "Pay now" on the order page after checkout: eSewa or Khalti, live confirmation while the provider settles | `/order-confirmed` |
| Payment attempts, stored provider redirects (`webhook_events`), replay detection, status re-check every five minutes with back-off | `ProviderPaymentService`, `payments:recheck` |
| Review queue for payments the system will not settle by itself, with email and in-app notice to finance | finance dashboard, order page |
| Disputes and chargebacks: holding commission, resolving won or lost, a lost dispute recorded as a refund | `DisputeService`, order page |
| Delivery steps (preparing, shipped with courier and tracking, delivered), shipping email, shown in the customer's order history | `FulfilmentService`, order page, `/account/orders` |
| Receipt email when a payment is confirmed (online or manual) | `OrderPaymentReceived` |
| Decision record, API spec (1.0.0-phase7), setup notes | [ADR 0011](adr/0011-payments-disputes-fulfilment.md), `docs/api/openapi.yaml`, README |

The order lifecycle now covers these events:

- created
- payment started
- payment confirmed
- processing
- shipped
- delivered
- cancelled
- refund
- dispute opened, won or lost

Each event is audited.

## How payment confirmation stays safe

- A redirect from eSewa or Khalti is never proof of payment. The server asks the provider's status API, and only "COMPLETE" or "Completed" for the exact amount marks the order paid.
- A forged eSewa redirect fails the signature check and is rejected without even asking eSewa.
- A replayed redirect, a second tab, the scheduled re-check and a staff "check now" together still produce exactly one payment, because of database locks and unique keys.
- These cases go to staff review instead of changing anything:
  - wrong amount
  - order already paid or cancelled
  - provider transaction already used
  - payment pending after 48 hours
  - a refund reported by the provider
- Sandbox mode is refused when the app runs in production.

## Verification (actual results)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | 281 passed (18 new) |
| `php artisan test` on MySQL 8.0.46 | 281 passed |
| `vendor/bin/pint --test`, permissions matrix check | pass |
| `npm run typecheck`, `npm run lint`, `npm run build` | pass |
| OpenAPI (Redocly lint) | valid (style warnings only, as before) |
| Browser test (Chromium, phone width, MySQL, dev seed, eSewa test merchant) | passed (below) |

### Provider behaviour tested

The tests use the shapes of the providers' documented responses:

- eSewa's documented signature example, which the signer reproduces exactly
- a full eSewa payment, with a replayed redirect
- a signed redirect alone does not pay, and the later re-check does
- a forged redirect
- amount mismatch
- a payment after the order was already paid by hand
- the provider unreachable, both by timeout and by an HTTP error from a proxy
- a failure arriving after success
- abandoned attempts expiring
- Khalti completed, cancelled and below the minimum
- a provider refund
- two copies of the same payment racing

### Browser test

1. On a phone-sized screen, a guest placed an order and saw "Pay with eSewa".
2. The browser posted a signed form to eSewa's test address. The signature matched an independent HMAC of the signed fields.
3. A signed "COMPLETE" redirect then came back. The order was **not** marked paid, because eSewa's status API could not be reached from the test machine. The customer saw "We are confirming your payment", and the "Pay now" button was hidden so they would not pay twice.
4. A staff member pressed "Check with eSewa now" and was told eSewa did not answer and that it will be checked again automatically.
5. The staff member then:
   - moved the order through preparing and shipped (courier and tracking)
   - confirmed a bank payment
   - opened a dispute, which held the commission
   - closed the dispute as won
6. There were no page errors and no sideways scrolling.

The browser test found one real bug, which is now fixed and covered by a test: an HTTP error from the provider (rather than a timeout) showed the customer an error page instead of "confirming".

## Not done here, and why

- **The live sandboxes were not reachable.** The build environment's network blocks `rc-epay.esewa.com.np`, `rc.esewa.com.np` and `dev.khalti.com`. Before going live:
  1. Run one eSewa and one Khalti sandbox payment end to end, using eSewa test IDs 9711111111–9711111114 and Khalti test IDs 9800000000–9800000005.
  2. Then switch to production keys.
- **Keys:**
  - Khalti's sandbox key (test-admin.khalti.com) and both production merchant keys must come from the business.
  - Keys go in `.env` only and are never committed.
- **Refunds through the provider API:** refunds are made in the eSewa or Khalti merchant portal and then recorded on the order. The system flags any provider refund that was not recorded.
- **Courier integration:** the courier name and tracking number are entered by hand.
- **Fonepay:** no public merchant API is documented, so Fonepay stays a manual payment method.
