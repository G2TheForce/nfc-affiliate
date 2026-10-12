# Business requirements and assumptions

Release 1.0.0, branch `phase-9-release`, 2026-10-12.

This document has three parts:

1. What the business needs, in short (from master prompt sections 1 and 2 and the rest of the spec).
2. The assumptions and decisions the build actually uses, each checked against the code or config, with its source.
3. Decisions the owner still has to make before or after going live.

To see what is built and what is not, read [feature-checklist.md](feature-checklist.md).

---

## 1. Business requirements

### The business

- An online store in Nepal sells smart NFC products: NFC and PVC business cards, custom-branded cards, QR cards, review cards and stands, Wi-Fi sharing products, digital profile services and bulk corporate orders. Admins can add other products.
- Currency NPR. Timezone Asia/Kathmandu. The design must allow other currencies, countries, commission types and payment providers later without a rewrite.
- Business rules must not be hardcoded across the code.

### What the affiliate programme must do

- People apply to become affiliates. Staff approve, reject, suspend and reactivate them, with a recorded history.
- Approved affiliates get a referral link and code and promote products on social media, websites, messaging apps and offline.
- Affiliates earn commission only on eligible, paid orders attributed to them by a documented rule.
- The company keeps full control of membership, products, commission rules, orders, fraud review, payments, payouts and financial reports.
- Money must be exact and auditable: no floats, an append-only ledger, no silent edits to balances, and corrections made by recorded adjustments.
- Refunds reverse only the refunded share of commission, once.
- Affiliates request payouts of their available balance. Finance reviews and pays them, and no balance can be spent twice.
- Customer payments come only from verified server-side sources, never a browser redirect.
- Staff roles follow least privilege: Super Administrator, Affiliate Manager, Finance Manager, Customer Support. Plus Affiliate Member and Customer.
- Affiliates and staff get dashboards and reports with real database figures, and permitted users can export them (CSV and XLSX).
- Features that need third-party accounts, credentials or approval must be named as such, not faked.

### Size and stack

- About 100 affiliates at launch, with room to grow (spec section 23).
- Laravel REST API with MySQL and Sanctum, a responsive web app, and an API that a future Flutter app can use. Linux in production, Windows for local development. Docker is optional.

---

## 2. Assumptions and decisions in the build

Each value below was checked in `config/affiliate.php`, `config/payments.php`, other config files or the code. "Env" is the `.env` key that overrides the default.

### 2.1 Project shape

| Decision | Value | Source |
|---|---|---|
| Existing store | None was found, so this app has its own catalog, checkout, orders and payments. A real store would plug in later. | `docs/phase-0-plan.md` section 1, ADR 0007 |
| Stack | Laravel 12, PHP 8.2+, MySQL 8, Sanctum, spatie/laravel-permission, React 19 + TypeScript + Vite, React Router 7, Tailwind, Recharts | `composer.json`, `package.json`, ADR 0005 |
| Tests | PHPUnit, not Pest | ADR 0005 |
| Queue and cache | `database` driver; Redis optional | ADR 0005, `docs/production-config.md` |
| Web auth | Sanctum session cookie with CSRF for the SPA; bearer tokens for API clients | ADR 0004 |
| API token lifetime | 30 days | `config/sanctum.php` `expiration` (env `SANCTUM_EXPIRATION`) |
| Email verification | Signed GET link (valid 60 minutes), not `POST /auth/verify-email` | ADR 0005, `docs/architecture.md` |
| Public identifiers | ULIDs (`public_id`) in URLs and API responses; database ids never leave the server | `docs/architecture.md` |
| Business rules location | `config/affiliate.php`, set from `.env`. There is no `system_settings` table, so changes need an `.env` edit and a config reload. | `config/affiliate.php` |

### 2.2 Money, currency and time

| Decision | Value | Source |
|---|---|---|
| Currency | NPR | `config/affiliate.php` `currency.default` (env `AFFILIATE_CURRENCY`) |
| Money storage | BIGINT minor units (paisa), next to a 3-letter currency code. NPR 1 = 100 paisa. | `currency.minor_units = 100`; ADR 0001 |
| Rates | Basis points (10% = 1000) | ADR 0001 |
| Rounding | Half-up to the paisa, once per commission line | ADR 0001, `app/Domain/Commissions/CommissionMath.php`, `CommissionMathTest` |
| Highest percentage rule | 50% (5000 bp), a guard against typos | `commissions.max_rate_bp` (env `AFFILIATE_COMMISSION_MAX_RATE_BP`) |
| Timezone | Business dates in Asia/Kathmandu; timestamps stored in UTC | `business_timezone` (env `AFFILIATE_BUSINESS_TIMEZONE`) |
| Report periods | Nepal calendar days, inclusive, at most 366 days, default last 30 days; daily chart buckets up to 92 days, then monthly | `app/Domain/Reporting/Period.php`, ADR 0010 |

### 2.3 Membership

| Decision | Value | Source |
|---|---|---|
| States | pending, approved, rejected, suspended, deactivated. No draft state. | `app/Enums/AffiliateStatus.php`, ADR 0006 |
| Who can apply | A verified user. Staff cannot be affiliates. | ADR 0006; `ApplicationTest::test_staff_cannot_apply`, `test_unverified_user_cannot_apply` |
| One membership per account | `affiliate_profiles.user_id` is unique | migration `2026_10_10_100000_create_affiliate_tables.php` |
| Re-apply after rejection | After 30 days | `applications.reapply_cooldown_days` (env `AFFILIATE_REAPPLY_COOLDOWN_DAYS`) |
| Referral code | 8 random characters with no look-alikes, assigned on first approval, permanent, never reused | `applications.referral_code_length = 8`, ADR 0006 |
| Rejection, suspension, deactivation | Reason required and shown to the member. Internal notes are never shown. | ADR 0006, `ReviewWorkflowTest` |
| Approval message | Optional message, saved in the history and emailed to the member | `AffiliateMembershipService::approve`; `ReviewWorkflowTest::test_approval_message_reaches_the_member_and_history` |
| Possible duplicates | Same phone or website host is flagged to reviewers, never decided automatically | `app/Domain/Affiliates/DuplicateSignals.php` |
| Terms | Versions are immutable. Applicants accept the current version. New versions are prompted but do **not** block earning. | ADR 0006; no terms check in `CommissionService` |
| Staff accounts | Created and disabled from the console only: `affiliate:create-admin`, `affiliate:create-staff --role=affiliate_manager|finance_manager|support`, `affiliate:set-user-status {email} {active|disabled} --reason=`. Disabling deletes the account's tokens and sessions. There is no staff screen. | `app/Console/Commands/`, `StaffAccountCommandsTest` |
| Roles and permissions | Fixed in code and re-synced on every seed. Not editable in the app. | `app/Support/Authorization/PermissionMatrix.php`, `docs/permissions-matrix.md` |

### 2.4 Referral tracking and attribution

| Decision | Value | Source |
|---|---|---|
| Default policy | **Last click.** First click is available as a setting. The policy in force is stored on each attribution. | `attribution.policy = last_click` (env `AFFILIATE_ATTRIBUTION_POLICY`), ADR 0002 |
| Attribution window | 30 days | `attribution.window_days` (env `AFFILIATE_ATTRIBUTION_WINDOW_DAYS`) |
| Precedence at checkout | Affiliate coupon, then typed referral code, then cookie click | `attribution.coupon_overrides_click = true`, ADR 0007 |
| Affiliates per order | Exactly one; no split | `order_attributions.order_id` unique, ADR 0002 |
| Manual attribution | Staff with `attribution.manual`, reason of at least 10 characters, audited | ADR 0007, `AdminStoreTest` |
| Cookie | Encrypted first-party `nfc_ref`, HttpOnly, SameSite=Lax, Secure in production, lasts for the window | `attribution.cookie`, ADR 0007 |
| Unique clicks | A repeat from the same visitor counts as unique again after 24 hours | `attribution.unique_click_hours = 24` |
| Click flood limit | Over 30 clicks per minute from one IP still redirect but are not recorded | `attribution.clicks_per_minute_per_ip` (env `AFFILIATE_CLICKS_PER_MINUTE_PER_IP`) |
| Not recorded | Unknown or ineligible affiliates, bots, link previews, prefetches | `app/Domain/Referrals/BotDetector.php`, ADR 0007 |
| Self-referral | Skipped; order saved with `attribution_status = blocked` and the reason | `AttributionResolver`, ADR 0007 |
| Data kept on a click | Landing path, referrer host, UTM tags, sub-id, keyed hashes of visitor id and IP. No raw IP, user agent or location. | ADR 0007 |
| Retention | Hashes cleared after 90 days; clicks deleted after 395 days; runs daily at 03:15 Nepal time | `privacy.click_metadata_retention_days`, `privacy.click_retention_days`; `routes/console.php` |
| Campaigns | No separate campaign entity. A "campaign" in reports is a coupon. | ADR 0010 |

### 2.5 Store, coupons and payments

| Decision | Value | Source |
|---|---|---|
| Prices | Set by the server from the catalog. The browser sends only product ids and quantities. | ADR 0007, `CouponCheckoutTest::test_client_cannot_set_prices_totals_or_status` |
| Order number prefix | `NFC` | `store.order_number_prefix` (env `STORE_ORDER_NUMBER_PREFIX`) |
| Delivery charge | Flat, default 0, until a courier is connected | `store.shipping_flat_minor` (env `STORE_SHIPPING_FLAT_MINOR`) |
| Max quantity per line | 100 | `store.max_quantity_per_line` |
| Coupons | One per order, no stacking. Discount spread over eligible lines by largest remainder. | ADR 0007, `DiscountAllocationTest` |
| Tax | Not recorded. Prices are treated as tax-inclusive. | ADR 0008 |
| Payment methods | Bank transfer, cash on delivery, eSewa, Khalti, Fonepay, other. eSewa and Khalti can be online; the rest are confirmed by staff. | `app/Enums/PaymentMethod.php`, ADR 0011 |
| Proof of payment | Only a staff confirmation with a reference, or the provider's status API (eSewa `transaction/status`, Khalti `epayment/lookup`). Redirects are never proof. | ADR 0008, ADR 0011 |
| Partial payments | Not supported. The amount must equal the order total. | ADR 0008, `PaymentCommissionTest::test_amount_must_equal_the_order_total` |
| Payment providers by default | Off. Sandbox mode is refused when `APP_ENV=production`. | `config/payments.php`, `OnlinePaymentTest::test_sandbox_mode_is_refused_in_production_and_tokens_are_required` |
| Re-checks of open payments | Every 5 minutes, backing off at 2, 5, 15, 30, 60, 180 and 360 minutes; give up after 48 hours | `config/payments.php` `recheck_minutes`, `give_up_after_hours`; `routes/console.php` |
| Khalti minimum | NPR 10 (1000 paisa) | `config/payments.php` |
| Refunds | Made in the bank or merchant portal first, then recorded in the app per line | ADR 0008, ADR 0011 |
| Disputes | Recorded by finance. Opening one holds commission. A lost dispute becomes a refund named `DISPUTE-...`. | ADR 0011 |
| Delivery | Status moves forward only (unfulfilled, processing, shipped, delivered). Courier and tracking number entered by hand. | ADR 0011 |

### 2.6 Commissions

| Decision | Value | Source |
|---|---|---|
| Default rates in production | **None.** Production seeding creates no rules, so nothing is earned until staff create at least a global default. The dev seeder adds a `[DEV] Global default 10%` rule only outside production. | ADR 0008, `database/seeders/DevelopmentSeeder.php`, `PaymentCommissionTest::test_no_matching_rule_means_no_commission` |
| Precedence | Product exclusion, category exclusion, affiliate override, product rate, category rate, plan default, global default. First match wins. | ADR 0008, `RuleResolver`, `RulePrecedenceTest` |
| Overlaps | Two active rules for the same target with overlapping dates are refused | ADR 0008 |
| Which rules apply | Rules as they stood when the order was placed; the plan at payment time | ADR 0008 |
| Base | Line total after its share of the order discount. Delivery and payment fees excluded. | ADR 0008 |
| Fixed rules | Per unit, capped at the line total | ADR 0008, `CommissionMathTest` |
| When commission is created | When the order is paid. One per order line. | ADR 0008 |
| Holding period | **14 days** from payment | `commissions.holding_period_days` (env `AFFILIATE_HOLDING_PERIOD_DAYS`) |
| Release after holding | Automatic | `commissions.auto_release = true` (env `AFFILIATE_COMMISSION_AUTO_RELEASE`) |
| Maturation and reconciliation | Maturation hourly; reconciliation daily at 03:30 Nepal time. Drift is reported, never auto-fixed. | `routes/console.php`, ADR 0008 |
| Affiliate not approved at payment | Commission created on hold | `PaymentCommissionTest::test_affiliate_suspended_before_payment_gets_a_held_commission` |
| Coupon that does not earn | No commission | `coupons.earns_commission`, ADR 0008 |
| Partial refund | Commission recomputed on what is left of the line; the difference is reversed | ADR 0008, `RefundReversalTest` |
| Refund after payout | Reversed from the available balance, which may go negative and is recovered from future commission | ADR 0008, ADR 0009 |
| Adjustments | Signed amount and reason, approved by a different person (maker-checker) | ADR 0008, `AdjustmentAndReconciliationTest` |
| Not built | Campaign overrides, tiered, recurring, multi-level. Multi-level stays off (`commissions.multi_level_enabled = false`, not read by any code). | ADR 0008, `config/affiliate.php` |

### 2.7 Payouts

| Decision | Value | Source |
|---|---|---|
| Payment of affiliates | **Manual.** Finance pays from the company bank or wallet and enters the transfer reference. No payout API. | ADR 0009, `PayoutService` |
| Minimum payout | **NPR 1,000** (100000 paisa) | `payouts.minimum_minor` (env `AFFILIATE_PAYOUT_MINIMUM_MINOR`) |
| Amount | The whole available balance at request time; no partial amount | ADR 0009 |
| Open payouts | One per affiliate (row lock plus unique `payouts.open_affiliate_profile_id`) | ADR 0009 |
| Eligibility | Approved membership, saved payout details, no open payout, available balance at or over the minimum | ADR 0009, `PayoutFlowTest::test_request_eligibility` |
| Separate approver and payer | Off by default; turn on with two or more finance staff | `payouts.require_separate_payer = false` (env `AFFILIATE_PAYOUT_REQUIRE_SEPARATE_PAYER`) |
| Methods | One per affiliate: bank transfer, eSewa or Khalti | `app/Enums/PayoutMethodType.php` |
| Payout details | Encrypted with `APP_KEY`; changing them needs the password and sends a security email; full details shown only to payers while the payout is approved, and each view is audited | ADR 0009 |
| Reference | Unique per method | ADR 0009, `PayoutFlowTest::test_reference_is_unique_per_method` |
| A paid payout | Final. Money returned by the bank is recorded as a positive adjustment. | ADR 0009 |
| Schedule, fees, tax withholding | None. Payouts are on request; nothing is withheld. | ADR 0009 |

### 2.8 Security and operations

| Decision | Value | Source |
|---|---|---|
| Login limits | 5 per minute per email and IP, 30 per minute per IP, 30 failures per hour per account | `auth.login_attempts_per_minute` (env `AUTH_LOGIN_ATTEMPTS_PER_MINUTE`), `docs/architecture.md` |
| General API limit | 120 per minute per user or IP | `docs/architecture.md`, `InputHardeningTest::test_every_api_route_has_a_general_rate_limit` |
| Password hashing | bcrypt, 12 rounds | `.env.example` `BCRYPT_ROUNDS=12` |
| Audit log | Append-only; secrets redacted; IPs as keyed hashes | `AuditLogger`, `AuditLogTest` |
| Exports | Streamed, formula-safe, audited, 10 per minute per user | ADR 0010 |
| Notifications | Queued, sent after commit, 5 retries with backoff | `docs/security-review.md`, `ProductionConfigTest` |
| CORS | Framework default (any origin, no credentials); no `config/cors.php` | `docs/security-review.md` |
| MFA for staff | Not built (optional in the spec) | `docs/security-review.md` |
| Backups | Encrypted, checksummed; restore tested | `docs/backup-and-restore.md` |
| Docker | Optional production stack: nginx, PHP-FPM, queue, scheduler and MySQL with health checks. Only nginx publishes a port, on 127.0.0.1 by default; HTTPS goes in front of it. MySQL is never published. | `compose.production.yml`, `docker/` |

---

## 3. Open decisions for the owner

These are not settled. The build uses the default shown until the owner decides.

### Must decide before going live

| # | Decision | Current default | Where to change it |
|---|---|---|---|
| 1 | **Commission rates.** At least a global default rate; product, category or plan rates if wanted. | None, so nothing is earned | Admin, Commission rules |
| 2 | **Affiliate terms and privacy notice text**, including banned promotion methods and spam rules | None in production | Admin, Terms (`settings.manage`) |
| 3 | **eSewa and Khalti production keys**, after one live sandbox payment each | Providers off | `.env` `ESEWA_*`, `KHALTI_*` |
| 4 | **Email provider** and sending domain (SPF, DKIM) | Log mailer | `.env` `MAIL_*` |
| 5 | **VAT in the commission base.** Today prices are treated as tax-inclusive and VAT is not excluded. | Not excluded | Needs code; ask a Nepal tax advisor |
| 6 | **TDS on affiliate payouts** and whether to collect PAN | Nothing withheld | Needs code; ask a Nepal tax advisor |
| 7 | **Legal review** of privacy, e-commerce, consumer protection and payment rules (`docs/phase-0-plan.md` section 6) | Not reviewed | Qualified local adviser |
| 8 | **Cookie consent** for the referral cookie | No banner | Legal review, then code if needed |

### Confirm or change the defaults

| # | Decision | Current default | Where to change it |
|---|---|---|---|
| 9 | Attribution policy and window | Last click, 30 days, coupon beats click | `AFFILIATE_ATTRIBUTION_POLICY`, `AFFILIATE_ATTRIBUTION_WINDOW_DAYS` |
| 10 | Holding period | 14 days | `AFFILIATE_HOLDING_PERIOD_DAYS` |
| 11 | Automatic release after holding | On | `AFFILIATE_COMMISSION_AUTO_RELEASE` |
| 12 | Payout minimum | NPR 1,000 | `AFFILIATE_PAYOUT_MINIMUM_MINOR` |
| 13 | Separate approver and payer | Off | `AFFILIATE_PAYOUT_REQUIRE_SEPARATE_PAYER` |
| 14 | Payout schedule (for example monthly) instead of on request | On request | Needs code |
| 15 | Must members accept new terms before they keep earning? | Prompted, not blocked | Needs code (ADR 0006) |
| 16 | Re-apply cooldown | 30 days | `AFFILIATE_REAPPLY_COOLDOWN_DAYS` |
| 17 | Click data retention | 90 days for hashes, 395 days for clicks | `AFFILIATE_CLICK_METADATA_RETENTION_DAYS`, `AFFILIATE_CLICK_RETENTION_DAYS` |
| 18 | Flat delivery charge | NPR 0 | `STORE_SHIPPING_FLAT_MINOR` |
| 19 | Commission on bulk corporate and replacement orders | Treated like any order; not documented | Policy decision, then rules or code |
| 20 | Registration says when an email is already taken | Yes | Sign-up change (`docs/security-review.md`) |
| 21 | Staff limited to browser sessions (no API tokens) | Tokens allowed | Code (`docs/security-review.md`) |
| 22 | Multi-level commissions | Off | Stays off unless a lawyer approves |

### Features to schedule or drop

| # | Feature | Status |
|---|---|---|
| 23 | Campaigns as a real feature (dates, products, affiliates, commission overrides) | Not built; coupons stand in |
| 24 | Marketing assets for affiliates | Not built |
| 25 | Fraud flags, risk thresholds and a review queue | Not built; only holds and the payment review queue |
| 26 | Public affiliate landing page with FAQ | Not built |
| 27 | Staff and settings screens | Console and `.env` only |
| 28 | MFA for staff | Not built; the most useful next security feature |
| 29 | Notices for new attributed order, new commission, commission reversal | Not built |
| 30 | Phone verification by SMS | Needs an SMS provider (for example Sparrow SMS or Aakash SMS) |
| 31 | Automated payouts through a bank or wallet API | Needs a provider with a verified payout API |
| 32 | Courier integration | Manual courier and tracking entry today |
| 33 | Product images, stock and per-product shipping | Not built |
