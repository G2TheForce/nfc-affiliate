# ADR 0007: Built-in store and referral tracking

Status: accepted (Phase 3)

## Context

There is no existing store to integrate with, but referral attribution has to be verified against a real shopping journey: link, browse, cart, checkout. Phase 0 decided to build a minimal store first and connect real payment providers in Phase 7.

## Decision

### Store

- Catalog (`products`, `product_categories`), affiliate coupons (`coupons`, `coupon_product`, `coupon_redemptions`) and orders (`orders`, `order_items`) live in this application. Money is BIGINT paisa with an explicit currency (ADR 0001).
- Checkout prices every line from the catalog on the server. The browser sends only product ids, quantities and contact details.
- Orders start as `pending_payment`. Nothing in Phase 3 marks an order paid: that comes only from a verified payment record (Phase 4 manual confirmation, Phase 7 gateways). A placed order is never a sale.
- One coupon per order; coupons do not stack. The discount is allocated across eligible lines with the largest-remainder method, so line totals always add up to the order (Phase 4 needs net revenue per line).
- Coupon usage and per-customer limits are counted from non-voided redemptions while the coupon row is locked, so concurrent checkouts cannot exceed them. Cancelling an unpaid order voids its redemption.
- Checkout accepts an `Idempotency-Key` header. The same key with the same request returns the original order (200); the same key with a different request is rejected (409).
- The store frontend shows "You were referred by X" (the affiliate's display name only).

### Referral links

- `/ref/{code}` and `/ref/{code}/{link}` accept the affiliate's referral code or slug. `{link}` is a tracking link the affiliate created, optionally pointing at a product and carrying UTM tags.
- The redirect target comes from the database (the link's product) or a fixed allow-list (`/`, `/shop`, `/shop/{existing product}`). Anything else goes to `/`. There is no way to redirect off-site.
- A click is not recorded, and no cookie is set, for unknown or ineligible affiliates, obvious bots and link-preview fetchers (WhatsApp, Facebook, Viber, Telegram and so on), browser prefetches, or more than 30 clicks per minute from one IP. The visitor is still redirected.
- The route runs without a session or CSRF cookie, so clicks never create session rows.

### Attribution

- The attribution cookie `nfc_ref` holds the click's public id. It is encrypted, `HttpOnly`, `SameSite=Lax`, `Secure` in production, and lasts for the attribution window (30 days by default).
- **Default policy: last click.** A new valid click replaces the cookie. `AFFILIATE_ATTRIBUTION_POLICY=first_click` keeps an earlier valid click instead. The policy in force is stored on each attribution, so changing it never rewrites history.
- **Precedence at checkout** (with `coupon_overrides_click = true`, the default): affiliate coupon, then a referral code typed at checkout, then the cookie click. With the setting off, the click comes first.
- Candidates whose affiliate cannot earn (not approved) and self-referrals (the buyer's account, email or phone is the affiliate's) are skipped. If every candidate is skipped, the order is saved with `attribution_status = blocked` and the reason, so support can see why nobody was credited.
- Exactly one affiliate per order (`order_attributions.order_id` is unique).
- Staff with `attribution.manual` can assign an order to an approved affiliate with a reason of at least 10 characters. Self-referrals and cancelled orders are refused. The previous affiliate and source are written to the audit log.
- Native and API clients do not use the cookie: Sanctum reads encrypted cookies only on first-party SPA requests. They pass `referral_code` at checkout instead.

### Events and reporting

- `referral_events` records order lifecycle events per affiliate (`order_placed`, `order_cancelled`; later `order_paid`, `order_refunded`, `commission_approved`). The unique `dedupe_key` makes recording idempotent.
- Reports read clicks from `referral_clicks` and orders from `order_attributions`. Placed orders, cancellations and order value are shown separately from sales, which arrive with commissions in Phase 4.
- Daily series are grouped in the business timezone (Asia/Kathmandu) in PHP, so MySQL and SQLite give the same result.

### Privacy

- Clicks store a landing path, referrer host, UTM tags, a sub-id, and two keyed hashes: one of a random first-party visitor id (to tell unique clicks from repeats) and one of the IP (to correlate abuse). No raw IP, user agent, fingerprint or location is stored.
- `affiliate:prune-referral-data` runs daily. It clears both hashes after 90 days (`AFFILIATE_CLICK_METADATA_RETENTION_DAYS`) and deletes clicks after 395 days (`AFFILIATE_CLICK_RETENTION_DAYS`). Attributions keep the click time.
- Affiliates never see customer names, emails, phones or full order numbers. Staff screens mask email and phone.

## Not in Phase 3

- Payments, paid status, refunds and commissions (Phases 4 and 7).
- Stock levels, shipping providers and tax.
- Landing-page, add-to-cart and checkout-started events: they are optional in the spec and not needed for attribution.
- Cookie consent banner. The attribution cookie is first-party and functional. Whether Nepal law or the store's privacy notice requires consent is a question for the business's legal review.
