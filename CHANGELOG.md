# Changelog

## 1.3.0 (Unreleased)

A new look for the whole app, and the store becomes installable as a phone app.

- **Visual direction "midnight holographic"**: deep indigo brand with a cyan-to-violet signal gradient, a new logo, favicon and app icons, and a share preview image (`public/og-image.jpg`) for links posted on social media.
- **Dark mode** everywhere, following the device or chosen with the theme button in each header. The choice is kept in the browser. The theme is applied before the page draws (`public/theme-init.js`, a file so the production CSP still allows no inline scripts).
- **Storefront**: a home hero with an animated card-and-phone scene, illustrated "how it works" steps, a footer, generated card artwork for products without a photo, and a product page with a 3D tilt preview and "Preview with your name".
- **Sign-in and account screens**: a split sign-in layout with brand artwork, sticky staff and account navigation with an active marker, initials avatar, and an illustrated 404 page.
- **Installable app (PWA)**: `/manifest.webmanifest` (named after `APP_NAME`), a service worker (`public/sw.js`) that caches only hashed build files and icons and shows `public/offline.html` when there is no connection. API calls, payments and personal data are never cached.
- Pages now load on first visit (route code splitting), so shoppers do not download the staff screens.
- **Digital tap profiles** ([ADR 0012](docs/adr/0012-tap-profiles.md)): every verified user can publish the page their NFC card opens, at `/p/{handle}`, with call, WhatsApp, email and website buttons, social links, six colour themes and **Save contact** (a vCard). The editor (`/account/card`) has a live preview, a QR code to download, **Write to an NFC tag** on Android Chrome, and 30-day views and saves. An approved affiliate's profile links to their referral code, so every tap can earn them commission. Link previews show the person's name.
- **Flutter mobile app** ([mobile/README.md](mobile/README.md)) for customers and affiliates: shop with cart, coupons, checkout and eSewa/Khalti payment in an in-app browser; the affiliate area (apply, overview, referral links with QR and sharing, campaigns, earnings, payouts and payout details, marketing materials); the tap card editor with stats; orders, notifications and profile. It uses the existing `/api/v1` with bearer tokens, so no backend changes. CI runs `flutter analyze` and `flutter test`, and the **Mobile APK** workflow publishes an installable test APK as the `mobile-test` pre-release. The server address can be changed in the app (Account > Server address).
- API spec version 1.3.0 with `GET /cards/{handle}`, `GET/PUT /card-profile` and `GET /card-profile/stats`.

### Database changes

One new migration creates `card_profiles` and `card_profile_daily_stats`. It does not change existing data.

## 1.2.0

Adds the staff and settings screens, profile editing, and product photos (Phase 11). [Report](docs/phase-11-report.md)

- **Staff screen** (`/admin/staff`, Super Administrator): invite staff by email with a link to set their own password, resend the link until they first sign in, change a member's role, and disable or re-enable an account with a reason. A role change or a disable signs the person out everywhere. Nobody can change their own role or disable themselves, and the last active Super Administrator can be neither demoted nor disabled. `affiliate:set-user-status` now uses the same rules.
- **Settings screen** (`/admin/settings`, Super Administrator): 18 programme settings (attribution, holding period and auto-release, payout minimum and separate payer, delivery charge, reapply wait and fraud thresholds) can be changed without the server. A saved value wins over `.env`, which now holds the defaults. **Use default** removes the saved value. Changes apply at once, queue workers restart after their current job, and every change is audited.
- **My profile** (`/account/profile` and `/admin/profile`): everyone can change their name and phone, and change their password with the current one. A password change signs out other devices and API tokens and sends a "Your password was changed" email. Email changes still go through support.
- **Product photos and categories**: one JPG, PNG or WebP photo per product (up to 5 MB), shown in the shop and on the product page and served from `/media/products/`. Categories can be renamed, hidden and deleted when nothing uses them; each change is saved and audited in one transaction.
- API spec version 1.2.0 with the new endpoints: `PATCH /auth/me`, `POST /auth/password`, `/admin/staff` (including `POST /admin/staff/{user}/role`), `/admin/settings`, `POST/DELETE /admin/products/{product}/image` and `PATCH/DELETE /admin/categories/{category}`.

### Database changes

Two new migrations create `system_settings` and add a nullable `image_path` to `products`. Neither changes or removes existing data. Back up `storage/app/private/products` with the other files. See [docs/migrations.md](docs/migrations.md).

## 1.1.0

Closes the main specification gaps left in 1.0.0 (Phase 10). [Report](docs/phase-10-report.md)

- **Affiliate notices**: an email and in-app notice when a referred order is paid (masked order number, commission and when it becomes payable, or that no commission applies), and when commission is reversed by a refund or cancelled by staff (never with the staff reason). Everyone gets a "password changed" email after a password reset.
- **Fraud signals and review queue**: a nightly check (04:00 Nepal time) for unusually high conversion, many clicks with no orders, high refund rates and sudden earnings jumps, and a check on each payout request for large amounts and recent payout-detail changes. Thresholds are set in `.env` (`AFFILIATE_FRAUD_*`). Flags go to a **Fraud review** screen and show on the payout screen; they never block or hold anything by themselves.
- **Public programme page**: `/affiliates` ("Earn with us" in the store menu) explains the programme from the live settings, the global rate and the current terms.
- **Campaigns**: dated promotions for chosen products and affiliates, with their own commission rule (after exclusions, before affiliate overrides). Orders join a campaign through a campaign tracking link or a campaign coupon. Staff see orders, sales and commission against an informational budget. Affiliates see open campaigns and make campaign links on **Referral links**.
- **Marketing materials**: staff upload images, MP4 videos, PDFs, ZIP files (up to 20 MB) or post text; affiliates browse, download and copy them. File types are checked from the content, SVG is refused, and files are served only through the API.
- API spec version 1.1.0 with the new endpoints. Upload limits raised in `docker/php.ini` and `docker/nginx.conf`.

### Database changes

Three new migrations create `fraud_flags`, the campaign tables and `marketing_assets`, and add a nullable `campaign_id` to links, coupons, attributions and commission rules. None changes or removes existing data. See [docs/migrations.md](docs/migrations.md).

## 1.0.0 (release candidate, 2026-10-12)

First production release. Built in phases; each phase report in `docs/` lists what was built and tested.

- **Accounts and access** (Phase 1): registration, email verification, password reset, Sanctum session and token sign-in, four staff roles plus affiliate and customer, append-only audit log. [Report](docs/phase-1-report.md)
- **Affiliate membership** (Phase 2): applications with duplicate warnings, staff review, suspend, deactivate and reactivate, versioned terms, member self-service and notifications. [Report](docs/phase-2-report.md)
- **Store and referral tracking** (Phase 3): catalogue, coupons, checkout with idempotency, referral links and codes, bot filtering, last-click attribution with a 30-day window, manual attribution. [Report](docs/phase-3-report.md)
- **Commission accounting** (Phase 4): rules with precedence and plans, an append-only ledger, a holding period with automatic release, holds, two-person adjustments, nightly reconciliation. [Report](docs/phase-4-report.md)
- **Payouts** (Phase 5): encrypted payout details, requests that reserve the balance, finance approval, manual transfer confirmation with a unique reference. [Report](docs/phase-5-report.md)
- **Dashboards and reports** (Phase 6): affiliate, staff and finance dashboards; 12 staff and 4 affiliate reports with CSV and Excel export. [Report](docs/phase-6-report.md)
- **Payments and fulfilment** (Phase 7): eSewa and Khalti, confirmed only by the provider's status API; review queue; disputes; delivery steps; receipts. [Report](docs/phase-7-report.md)
- **Security and performance** (Phase 8): security review and fixes, concurrency and load tests, indexes, queue reliability, encrypted backups with a tested restore. [Report](docs/phase-8-report.md)
- **Release preparation** (Phase 9): deployment and operations guides, Docker Compose production stack, user guides, staff account commands, release-readiness assessment. [Report](docs/phase-9-report.md)

### Database changes

All 14 migrations create tables or add indexes; none changes or removes existing data. See [docs/migrations.md](docs/migrations.md).
