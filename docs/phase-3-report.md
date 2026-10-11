# Phase 3 report: Referral tracking

Date: 2026-10-11 · Status: **complete, verified in a test environment**

## What was built

| Item | Where |
|---|---|
| Built-in store: categories, products, guest or signed-in checkout, customer order history | `app/Domain/Store`, `/api/v1/store/*`, `/api/v1/orders` |
| Affiliate and store-wide coupons: percent or fixed, cap, minimum order, product restriction, total and per-customer limits, dates; one per order; discount allocated across lines | `CouponEvaluator`, `/api/v1/admin/coupons` |
| Referral links `/ref/{code}` and tracking links `/ref/{code}/{link}` with product destinations and UTM tags | `ReferralRedirectController`, `ReferralTracker`, `/api/v1/affiliate/links` |
| Open-redirect protection: destinations from the database or an allow-list only | `DestinationResolver` |
| Click recording with minimised metadata (keyed hashes, no raw IP or user agent), unique-click dedupe, bot, preview and prefetch filtering, per-IP flood limit | `ReferralTracker`, `BotDetector` |
| Encrypted first-party attribution cookie, last-click 30-day default, optional first-click | `config/affiliate.php`, ADR 0007 |
| Attribution at checkout: affiliate coupon, then typed referral code, then click; self-referral and inactive-affiliate protection; blocked reason recorded | `AttributionResolver`, `CheckoutService` |
| Manual attribution by staff with reason and audit trail | `ManualAttributionService`, `/api/v1/admin/orders/{order}/attribution` |
| Duplicate protection: idempotent checkout (`Idempotency-Key`), idempotent referral events, one attribution per order, coupon limits under row lock | `CheckoutService`, `ReferralEventRecorder` |
| Referral reporting: affiliate summary (clicks, unique clicks, placed and cancelled orders, order value, conversion, daily series, per link, per source), attributed orders without customer data; staff programme summary | `ReferralReport` |
| Staff: orders list and detail (masked contact), cancel unpaid orders, products and categories, coupons, referral report | `/admin/*` screens |
| Retention: click hashes cleared after 90 days, clicks deleted after 395 days, scheduled daily | `affiliate:prune-referral-data` |
| New permissions `catalog.manage` and `orders.manage` (Super Administrator only by default) | [permissions matrix](permissions-matrix.md) |
| Web screens: shop, product, cart with coupon, checkout, confirmation, "referred by" banner; My orders; Referral links; Referral stats | `resources/js/pages` |

## Verification (actual results)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | 158 passed (768 assertions); 67 are new in Phase 3 |
| `php artisan test` on MySQL 8.0.46 | 158 passed |
| `npm run typecheck`, `npm run lint`, `npm run build` | pass |
| `vendor/bin/pint --test`, `affiliate:permissions-matrix --check` | pass |
| `composer audit`, `npm audit` | no advisories |
| OpenAPI (Redocly lint) | valid |
| Browser test (Chromium, MySQL, phone-sized screen) | passed (below) |

The browser test covered the real store journey:
1. The customer opened `/ref/{code}?utm_source=facebook`, landed on the shop and saw "You were referred by [DEV] Approved Creator".
2. They added a product, went back to the shop, added a second product, and checked out as a guest.
3. The order was credited to the affiliate (source: referral link).
4. The affiliate's referral stats showed the click and the order.
5. The affiliate created a product tracking link.
6. Support saw the order with its attribution and the customer's email and phone masked.

A second run used the affiliate coupon `DEVCREATOR10` with no referral link:
1. An unknown code was refused.
2. The coupon applied a 10% discount.
3. The order was credited to the coupon's affiliate, and the cart emptied after the order.

Defects found by these checks and fixed before the PR:
- **MySQL index name too long:** one index name exceeded MySQL's 64-character limit.
- **Repeated data loading on the staff orders list:** the list reloaded each order's staff decision separately.
- **Collection detail flag:** collection resources passed the item key into a constructor flag.
- **Lost audit value:** manual attribution overwrote the previous source before writing it to the audit log.
- **Bounce back to the cart:** checkout redirected to the cart instead of the confirmation page.
- **Sessions created by clicks:** referral clicks created session rows. The route now runs without a session.

## Not built in this phase (by design)

- Payments, paid status, refunds and commissions. Orders stay "Awaiting payment" until Phase 4 (manual verified payment) and Phase 7 (eSewa and Khalti).
- Stock levels, shipping providers and tax.
- Optional landing-page, add-to-cart and checkout-started events.

## Needs a business decision

- **Cookie consent:** whether a consent banner is needed for the first-party attribution cookie. It is functional and first-party; legal review should confirm.
- **Delivery charge:** flat rate, currently NPR 0 (`STORE_SHIPPING_FLAT_MINOR`).
- **Self-referral matching:** self-referrals are matched on account, email or phone. Say if a looser or stricter rule is wanted.
- **Still open from Phase 2:** the real affiliate terms text, and whether unaccepted new terms should block earning.

## Known follow-ups

- `composer.json` still declares `php: ^8.2`. The locked dependencies need 8.3, and CI and the docs use 8.3. Changing the constraint was blocked by this environment's safety check, so it needs an explicit go-ahead.
- Three unused workflow files from the Laravel starter template (`issues.yml`, `pull-requests.yml`, `update-changelog.yml`) can be deleted once approved.
