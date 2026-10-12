# API integration guide

For developers connecting a mobile app, the existing store, or back-office scripts to the NFC affiliate system. This guide explains how the API behaves. Full request and response schemas are in [`docs/api/openapi.yaml`](api/openapi.yaml). The overall design is in [architecture.md](architecture.md).

Everything below is taken from `routes/api.php`, `routes/web.php`, the controllers under `app/Http/Controllers`, `bootstrap/app.php`, `AppServiceProvider` and `config/sanctum.php`. If this guide and the code ever disagree, the code is correct.

## 1. Base URL and versioning

- Every endpoint is under `https://<host>/api/v1` (for example `https://store.example/api/v1/auth/login`). Locally the host is `http://localhost:8000`.
- The version is part of the path (`v1`). Breaking changes will go under a new prefix, and `v1` stays as it is.
- Send `Accept: application/json` and, on requests with a body, `Content-Type: application/json`. Every `/api/*` response is JSON, errors included (`shouldRenderJsonWhen` in `bootstrap/app.php`). Report exports and marketing material files are the only exceptions: they return a file. Uploading a marketing material (section 10.3) or a product photo (section 11.4) are the only requests sent as `multipart/form-data`.
- Always send the `Accept` header. The framework's `verified` middleware checks it: with the header, an unverified user gets a JSON 403; without it, they get a 302 redirect to `/verify-email`.
- Production forces `https` URLs (`URL::forceScheme('https')`).
- Health check: `GET /up` (outside `/api`).

## 2. Authentication

`auth:sanctum` accepts either a session cookie or a bearer token. Every authenticated route also uses the `active` middleware.

### 2.1 First-party web app (cookie session)

This mode is for the React SPA served from the same site. Do not use it from mobile apps or scripts.

1. `GET /sanctum/csrf-cookie` sets the `XSRF-TOKEN` cookie.
2. `POST /api/v1/auth/login` with `email` and `password` and **no `device_name`**. The server starts a session and returns `{"user": {...}}`.
3. Send the session cookie on every later request. Writes (POST, PATCH, PUT) must also send the `X-XSRF-TOKEN` header with the cookie's value. If it is missing or wrong, the server returns **419**.

Cookie sessions only work when the request's origin is in `SANCTUM_STATEFUL_DOMAINS` (`config/sanctum.php`; the default is `localhost`, `localhost:3000`, `127.0.0.1`, `127.0.0.1:8000`, `::1` plus the `APP_URL` host). Requests from any other origin get no session. If such a client calls login or register without `device_name`, it gets a 422 on `device_name` ("device_name is required when not signing in from the web app.").

Logout (`POST /auth/logout`) ends the session and regenerates the CSRF token. Session cookies are not affected by the token expiry described below.

### 2.2 Mobile apps and scripts (bearer tokens)

- Send `device_name` (any string up to 100 characters, for example `"android-pixel-8"`) to `POST /auth/login` or `POST /auth/register`. The response is `{"token": "<id>|<secret>", "token_type": "Bearer", "user": {...}}`. Store the token in secure storage. It cannot be retrieved again.
- Send `Authorization: Bearer <token>` on every request. Bearer requests do not need CSRF.
- **Expiry:** tokens expire after `SANCTUM_EXPIRATION` minutes, which defaults to 43200 (30 days). An expired token gets **401**. There is no refresh endpoint, so sign in again. Expired tokens are pruned daily (`sanctum:prune-expired --hours=24`).
- **Revocation:** `POST /auth/logout` with a bearer token deletes only that token. A password reset (`POST /auth/reset-password`) deletes all of the user's tokens. A password change (`POST /auth/password`, section 11.1) deletes every token except the one that made the request. Disabling an account deletes all its tokens.
- Each login creates a new token. Reuse the stored token rather than signing in on every app start.

### 2.3 Account state checks

| Situation | Result |
|---|---|
| No credentials, or an invalid or expired token | `401 {"message":"Unauthenticated."}` |
| Wrong email or password | `422`, error on `email` |
| Disabled account at login | `422`, `email`: "This account is disabled. Please contact support." |
| Disabled account that still holds a token or session | `403 {"message":"This account is disabled."}` on every authenticated route, and also on checkout and payment calls made while signed in |
| Email not verified | `403` on `/affiliate/*` and `/admin/*` (both use the `verified` middleware). `/auth/me`, `/orders` and `/notifications` still work |
| Missing permission | `403` (Spatie `permission:` middleware, or a check inside the controller) |

Email verification: the emailed link is a signed URL to `GET /auth/verify-email/{public_id}/{hash}` and is valid for 60 minutes. `POST /auth/email/verification-notification` sends a new link (6/min). It returns 202 when a link is queued and 200 when the email is already verified.

## 3. Conventions

### 3.1 Response envelope

- A single resource is returned as `{"data": {...}}`. Some endpoints add a `meta` object, for example checkout returns `meta.access_token` and refunds return `meta.commission_reversed_minor`.
- A list is returned as `{"data": [...], "links": {first, last, prev, next}, "meta": {current_page, from, last_page, path, per_page, to, total, links}}` (Laravel's paginated resource collection).
- Login and register are exceptions: they return `token`, `token_type` and `user` at the top level, with no `data` wrapper.

### 3.2 Pagination

- Use `?page=N`. The page size is fixed for each endpoint (for example 48 for store products, 25 for affiliate commissions, 50 for the affiliate ledger, 20 for notifications and customer orders).
- Only `GET /admin/audit-logs` and `GET /admin/affiliates` accept `per_page` (1 to 100, default 25).
- Filters are query parameters. Their names are listed per endpoint in the OpenAPI file, for example `status` on `/affiliate/earnings/commissions`.

### 3.3 Money

- Every amount is an **integer in minor units (paisa)**, in fields ending in `_minor`. For example, `150000` is NPR 1,500.00. Do not use floats.
- Each amount comes with a `currency` field (ISO 4217, `NPR` by default from `AFFILIATE_CURRENCY`).
- Percent rates are basis points (`rate_bp`, where 1000 = 10%). Coupon `discount_value` is basis points for `percent` coupons and paisa for `fixed` coupons.
- CSV and XLSX exports are the one exception: they write money in rupees with two decimals.

### 3.4 Identifiers and dates

- Resource `id` values are public **ULIDs** (26 characters, for example `01JA2Z6V8K3M4N5P6Q7R8S9T0V`), and URLs use the same ULIDs. Internal auto-increment ids never leave the server.
  - Exceptions: notification ids are UUIDs, audit log entries have integer ids, and categories use an integer id plus a `slug`.
- Timestamps are ISO-8601 strings in **UTC** (`2026-10-12T08:15:00+00:00`, since `config/app.php` uses timezone `UTC`).
- Report and dashboard `from` and `to` parameters are calendar dates (`YYYY-MM-DD`) in Nepal time (Asia/Kathmandu). Both ends are included, and a range can be at most one year.

## 4. Errors

Errors always contain a `message`. With `APP_DEBUG=false`, a 500 response is only `{"message":"Server Error"}`.

| Status | Meaning |
|---|---|
| 401 | Not signed in, or the token is invalid or expired |
| 403 | Missing permission, disabled account, unverified email (on affiliate and staff routes), or an invalid or expired signed link |
| 404 | Unknown resource, or one that belongs to someone else (customer orders, notifications, affiliate links, payouts, and orders without a valid access token) |
| 409 | `Idempotency-Key` reused with a different checkout body |
| 419 | CSRF token missing or mismatched (cookie sessions only) |
| 422 | Validation failed, or a business rule refused the action (wrong state, duplicate reference, amount mismatch) |
| 429 | Rate limited. Wait for the time in `Retry-After` |

Validation errors use Laravel's standard format. Business-rule refusals use the same format, with the field name as the key (for example `order`, `reference`, `provider` or `payout`):

```json
{
  "message": "The amount received must equal the order total. Partial payments are not supported.",
  "errors": { "amount_minor": ["The amount received must equal the order total. Partial payments are not supported."] }
}
```

## 5. Rate limits

Limiters are defined in `AppServiceProvider::configureRateLimiting()` and as `throttle:N,1` on routes. Every `/api` route also has the general `api` limit.

| Scope | Limit | Key |
|---|---|---|
| Every `/api` route (`api`) | 120/min | user id, or IP for guests |
| `POST /auth/login` (`login`) | 5/min (`AUTH_LOGIN_ATTEMPTS_PER_MINUTE`), plus 30/min, plus 30/hour | email+IP, IP, and account across all IPs |
| `POST /auth/register` | 10/hour | IP |
| `forgot-password`, `reset-password` | 5/min | IP |
| Verification link, resend verification | 6/min | throttle signature |
| Every `/store/*` route (`store`) | 60/min | IP |
| `POST /store/orders` and `POST /store/orders/{order}/payments` (`checkout`) | 10/min (on top of `store`) | IP |
| Affiliate application, membership closure, payout request, payout method change | 6/min | user |
| `POST /affiliate/links` | 30/min | user |
| `PATCH /auth/me` | 20/min | user |
| `POST /auth/password` | 6/min | user |
| `POST /admin/staff` | 20/min | user |
| `POST /admin/staff/{user}/invitation` | 10/min | user |
| `POST /admin/products/{product}/image` | 30/min | user |
| `GET /affiliate/marketing-assets/{asset}/file` | 120/min | user |
| `POST /admin/marketing-assets` | 30/min | user |
| Report exports (`exports`) | 10/min | user |
| `POST /admin/payment-attempts/{attempt}/recheck` | 20/min | user |
| `POST /admin/reconciliation` | 6/min | user |
| Provider return URLs (`/payments/...`) | 30/min | IP |

## 6. Checkout idempotency

`POST /store/orders` accepts an optional `Idempotency-Key` header. Use one on every checkout from a client that might retry, such as a mobile app on a poor connection.

- **Format:** 16 to 80 characters from `A-Z a-z 0-9 -` (a UUID v4 fits). Any other value returns 422 with `errors.idempotency_key`.
- **Scope:** the key is unique across the whole system (`orders.idempotency_key` has a unique index). Generate a new key for each checkout attempt the user starts, and keep the same key for retries of that attempt.
- **Replay:** if the same key arrives again with the same body from the same customer, no new order is created. The response is **200**, not 201, and contains the original order.
- **Conflict:** if the same key arrives with a different body or a different signed-in customer, the response is **409** ("This checkout key was already used for a different order.").
- Two requests with the same key at the same time also produce one order. The request that loses the race gets the replay response.

## 7. Guest order access token

Every successful checkout response includes `meta.access_token`, a 48-character secret. Only its SHA-256 hash is stored (`orders.access_token_hash`), and it is shown once.

- A guest uses it to start a payment and to poll payment status for that order. Send it as `access_token` in the JSON body of `POST /store/orders/{order}/payments`, and as `?access_token=` on `GET /store/orders/{order}/payment-status`.
- A signed-in customer who owns the order can leave it out.
- A missing or wrong token returns **404** ("Order not found."), never 403, so the response does not reveal that the order exists.
- An idempotent replay issues a **new** token and replaces the stored hash. After a replay, use the token from the latest response.
- The token does not give access to `/orders/{order}`. That endpoint is for signed-in owners only.

## 8. Online payments (eSewa, Khalti)

```
client                         API                              provider
  | GET /store/payment-methods  |                                   |
  | POST /orders/{id}/payments  |-- create attempt (initiated) ---> |
  |<-- redirect {type,url,...}  |                                   |
  |------------------- customer pays on provider page ------------->|
  |                             |<-- browser return /payments/... --|
  |                             |-- status API check -------------->|
  |<-- 302 /order-confirmed?order=..&payment=paid|failed|pending    |
  | GET /orders/{id}/payment-status (poll)                          |
```

1. **List methods:** `GET /store/payment-methods` returns `[{key, label, minimum_minor}]` for the providers switched on in `config/payments.php`.
2. **Start:** `POST /store/orders/{order}/payments` with `{"provider":"esewa"|"khalti","access_token":"..."}` returns **201** `{"data":{"attempt_id","redirect"}}`.
   - eSewa: `redirect.type` is `form`. Build an HTML form with `method="POST"` and `action=redirect.url`, add every entry of `redirect.fields` as a hidden input (they are signed), and submit it from a browser or webview.
   - Khalti: `redirect.type` is `redirect`. Open `redirect.url` in a browser or webview.
   - Returns 422 when the order is not `pending_payment`, the total is below the provider minimum, or the provider is switched off or not responding (all under `errors.order` or `errors.provider`). Each call creates a new attempt.
3. **Provider return:** the provider sends the browser to `/payments/esewa/return`, `/payments/esewa/failure/{attempt}` or `/payments/khalti/return`. The server records the callback in `webhook_events`, checks the status with the provider's server-side API, and redirects to `/order-confirmed?order=<id>&payment=paid|failed|pending` (or `payment=unknown`). Mobile apps can watch for that path in the webview and then poll.
4. **Poll:** `GET /store/orders/{order}/payment-status` returns `{"data":{"order_status","order_status_label","paid","attempt":{"provider","status","outcome"}|null}}`. The `outcome` is `paid`, `pending`, `failed` or `checking` (`checking` means staff review). While the attempt is open, the server asks the provider again at most once every 20 seconds per attempt, so poll every 3 to 5 seconds and stop when `paid` is true or `outcome` is `failed`.

**Only the provider's status API confirms a payment.** The return redirect, its query string and any client report prove nothing. An order is marked paid only when the status API reports the attempt complete for the exact amount. An amount mismatch, a duplicate transaction id, or a payment for an order that is already paid or cancelled moves the attempt to `needs_review` for staff. A scheduled job (`payments:recheck`, every five minutes) checks attempts that are still open.

The `/payments/*` URLs are **for providers only**. They are web routes with no session, no CSRF and no JSON response. Clients must never call them or build them.

## 9. Staff operations

Staff routes need a verified email, `admin.access` and the permission named in each route. Manual payments (bank transfer, cash on delivery, wallet transfers checked by hand) are confirmed with `POST /admin/orders/{order}/payments` (`payments.confirm`):

- The body is `method` (`bank_transfer`, `cash_on_delivery`, `esewa`, `khalti`, `fonepay`, `other`), `reference` (3 to 100 characters), `amount_minor` (must equal the order total exactly) and an optional `note`.
- Returns **201** with the order detail. Sending the same reference for the same order and amount returns **200** and changes nothing. A reference already used elsewhere, a cancelled order or an already-paid order returns 422.
- Confirming a payment marks the order paid and creates commissions in the same transaction.

## 10. Campaigns, marketing materials, fraud flags and the programme page (1.1.0)

### 10.1 Public programme facts

`GET /store/affiliate-programme` needs no sign-in (the `store` limit applies). It returns the figures shown on the public `/affiliates` page, read from the live configuration and rules:

- `currency`
- `standard_rate`: `{kind, rate_bp, fixed_minor}` from the newest global rule in force, or `null` when there is none. Plan, affiliate, product and campaign rates are never included.
- `attribution_window_days`, `holding_period_days`, `payout_minimum_minor`
- `payout_methods`: labels, for example `["Bank transfer", "eSewa", "Khalti"]`
- `terms`: `{version, title, body, published_at}` of the current terms, or `null`

### 10.2 Campaigns

- **Staff** (`campaigns.manage`): `GET /admin/campaigns` (`?state=running|upcoming|ended|all`, default `all`, 50 per page), `POST /admin/campaigns`, `GET /admin/campaigns/{campaign}` and `PATCH /admin/campaigns/{campaign}`. The two `GET` routes also accept `commission_rules.manage` or `commissions.view`. There is no delete; send `is_active: false` or an `ends_at` instead. Creates and updates are audited (`campaign.created`, `campaign.updated`).
- **Body:** `name` (3 to 120, required on create), `description`, `starts_at` (required on create), `ends_at` (after `starts_at`), `budget_minor`, `is_active`, `all_affiliates`, `affiliate_ids[]` and `product_ids[]` (public ids). With `all_affiliates: false`, at least one affiliate is required. An empty `product_ids` means every product. `budget_minor` is informational and never limits commission.
- **Response:** `id`, `name`, `description`, `starts_at`, `ends_at`, `state` (`running`, `upcoming` or `ended`), `is_active`, `budget_minor`, `currency`, `all_affiliates`, `affiliates[]`, `products[]`, `rule` (the campaign commission rule in force, or `null`) and `stats` (`orders`, `sales_minor`, `commission_minor`: paid and partly refunded orders, product sales after discounts, and net commission).
- **Rate:** a campaign's commission is a commission rule with `scope: "campaign"` and `target_id` set to the campaign id (`kind` `percent` or `fixed`; `exclude` is refused). It comes after exclusions and before affiliate overrides. `GET /admin/commission-rules/preview` and `GET /affiliate/earnings/rates` do not take campaigns into account.
- **Coupons:** `POST /admin/coupons` and `PATCH /admin/coupons/{coupon}` accept `campaign_id` (a campaign id, or `null` to unlink). Coupons return `campaign: {id, name}` or `null`.
- **Affiliates** (`affiliate.portal`): `GET /affiliate/campaigns` lists campaigns running now that are open to the signed-in member: `id`, `name`, `description`, `starts_at`, `ends_at`, `all_products`, `products[]`, `commission` (`{kind, rate_bp, fixed_minor, currency}` or `null`), `coupons[]` (the member's own active coupons for it: `code`, `discount_type`, `discount_value`) and `my_links` (count). `POST /affiliate/links` and `PATCH /affiliate/links/{link}` accept `campaign_id`. It must be a running campaign open to the member, or the request fails with 422 on `campaign_id`. Links return `campaign: {id, name}` or `null`.
- **Which orders count:** at checkout the order takes the campaign of the affiliate coupon used, else of the clicked tracking link, else of a store-wide coupon used. It counts only while the campaign runs and the credited affiliate takes part. The main referral link and a typed `referral_code` never bring a campaign.

### 10.3 Marketing materials

- **Staff** (`marketing_assets.manage`): `GET /admin/marketing-assets` (`?campaign_id=`, `?active=1|0`, 50 per page), `POST /admin/marketing-assets`, `PATCH /admin/marketing-assets/{asset}`, `DELETE /admin/marketing-assets/{asset}` (204; also deletes the file) and `GET /admin/marketing-assets/{asset}/file`. Changes are audited (`marketing_asset.created`, `.updated`, `.deleted`).
- **Upload:** send `POST /admin/marketing-assets` as `multipart/form-data` with `title` (2 to 120, required), `description`, `caption` (post text, up to 5,000), `campaign_id`, `product_id`, `is_active` (`1` or `0`) and `file`. The file is optional when `caption` is given (a text-only material). The type is detected from the file's content: JPEG, PNG, WebP, GIF, MP4, PDF or ZIP. Anything else, including SVG, gets 422 on `file`. The size limit is `AFFILIATE_MARKETING_MAX_UPLOAD_KB` (20,480 KB by default). `PATCH` takes the same fields as JSON, except `file`: a file cannot be replaced.
- **Response:** `id`, `title`, `description`, `kind` (`image`, `video`, `document`, `archive` or `text`), `caption`, `has_file`, `mime`, `size_bytes`, `campaign`, `product`, `created_at`. Staff responses also have `is_active`, `original_name` and `download_count`.
- **Affiliates** (`affiliate.portal`): `GET /affiliate/marketing-assets` returns up to 200 active materials, newest first, without paging. A material linked to a campaign is listed only while the campaign runs and only to members taking part. `GET /affiliate/marketing-assets/{asset}/file` returns 404 for anything not in that list.
- **Files:** both `.../file` endpoints send the file as an attachment named after the title, with `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`. Add `?inline=1` to show an image in the page instead (other kinds are always attachments). An affiliate download counts towards `download_count`; an inline preview and a staff download do not. Files are kept on the private disk and have no public URL.

### 10.4 Fraud flags

- `GET /admin/fraud-flags` (`fraud.review`): `?status=open|confirmed|dismissed|all` (default `open`), `?affiliate_id=`, 25 per page. `meta` has `current_page`, `last_page`, `total` and `open` (all open flags).
- Each flag: `id`, `signal` (`high_conversion`, `clicks_without_orders`, `refund_rate`, `rapid_earnings`, `large_payout`, `payout_after_detail_change`), `signal_label`, `guidance`, `severity` (`low`, `medium`, `high`), `details` (what was measured and the limit), `subject` (`{type: "payout", id}` or `null`), `status`, `status_label`, `affiliate` (`id`, `display_name`, `referral_code`), `detected_at`, `reviewed_by`, `reviewed_at`, `review_note`.
- `POST /admin/fraud-flags/{flag}/review` (`fraud.review`) with `outcome` (`confirmed` or `dismissed`) and `note` (5 to 1,000 characters). Returns the flag. A flag that is already reviewed gets 422 on `status`. Audited as `fraud_flag.reviewed`.
- Flags are raised by `affiliate:detect-fraud` (daily at 04:00 Nepal time) and when a payout is requested. They never change money or block a request.
- `GET /admin/payouts/{payout}` now also returns `open_fraud_flags`: `[{id, signal_label, severity, detected_at}]` for the payout's affiliate.

### 10.5 New notification types

`GET /notifications` can now contain `affiliate.referred_order_paid` and `affiliate.commission_reversed` (for affiliates, with `amount_minor` and `currency`) and `fraud.flags_raised` (for staff). The password-changed notice is email only.

## 11. Profile, staff, settings and product photos (1.2.0)

### 11.1 Your own profile and password

Any signed-in user (staff, affiliate or customer) can use these. Neither needs a verified email.

- `PATCH /auth/me` with `name` (2 to 120) and/or `phone` (E.164 with the country code, for example `+9779812345678`, or `null` to remove it). Other fields are ignored: email, roles and status cannot be changed here. Returns `{"data": User}`. A change is audited as `user.profile_updated` with the field names only, never the values.
- `POST /auth/password` with `current_password`, `password` and `password_confirmation`. The new password follows the registration rules and must differ from the current one. A wrong current password gets 422 on `current_password`. On success the password is changed, every other session and API token is deleted (the one making the request is kept; a cookie session gets a new session id), a "Your password was changed" email is queued, and the change is audited as `auth.password_changed`. Returns `{"data": User}`.

There is no endpoint to change an email address. It is changed by support, because the address is how an account is recovered.

### 11.2 Staff accounts

All need `admins.manage` (Super Administrator only). Staff are addressed by their user public id.

- `GET /admin/staff` returns `{"data": [StaffMember], "roles": [{value, label}]}`. Each member has `id`, `name`, `email`, `role`, `role_label`, `status`, `last_login_at`, `created_at` and `is_you`.
- `POST /admin/staff` with `name` (2 to 120), `email` (unused by any account) and `role` (`super_admin`, `affiliate_manager`, `finance_manager` or `support`). Returns **201**. The account starts with a verified email and a random password nobody knows, and a "Set your password" email is queued. The link is a normal password reset link to the web app's `/reset-password` page, valid for 60 minutes. Audited as `admin.staff_created` with `via: "screen"`. Affiliate and customer roles are refused with 422 on `role`.
- `POST /admin/staff/{user}/invitation` sends a new link. Someone who has already signed in gets 422 on `user` (they use forgot-password instead), and a disabled account gets 422 on `status`. Audited as `admin.staff_invitation_resent`.
- `POST /admin/staff/{user}/role` with `role` (`super_admin`, `affiliate_manager`, `finance_manager` or `support`; any other value gets 422). The old staff role is removed, the new one assigned, and the person's API tokens and sessions are deleted, so they sign in again with the new permissions. Changing your own role, changing a disabled account, or moving the last active Super Administrator to another role, gets 422 on `role`. Asking for the role they already have returns 200 and changes nothing. Audited as `admin.staff_role_changed` with `from` and `to`. Returns `{"data": StaffMember}`.
- `POST /admin/staff/{user}/status` with `status` (`active` or `disabled`) and `reason` (5 to 500). Disabling deletes the account's API tokens and sessions. You cannot disable yourself, and the last active Super Administrator cannot be disabled (422 on `status`). Audited as `user.status_changed` with `via`, `from`, `to` and `reason`. Asking for the status the account already has returns 200 and changes nothing.

A user without a staff role gets 404 on the last three.

The console commands `affiliate:create-staff`, `affiliate:create-admin` and `affiliate:set-user-status` still work. `affiliate:set-user-status` uses the same rules as the screen and is audited with `via: "console"`.

### 11.3 Programme settings

All need `settings.manage` (Super Administrator only).

- `GET /admin/settings` returns `{"data": [Setting], "currency": "NPR"}` for the 18 settings in `SettingsRegistry`. Each has `key` (a path under `config/affiliate.php`, for example `payouts.minimum_minor`), `group`, `label`, `help`, `type` (`int`, `money`, `bool` or `choice`), `min`, `max`, `choices`, `value` (in force now), `default` (from `.env`), `overridden`, `updated_at` and `updated_by` (a name).
- `PATCH /admin/settings` with `{"settings": [{"key": "...", "value": ...}]}` (1 to 50 items). `money` values are paisa. If any value is out of range, nothing is saved and the error is under `settings.N.value`. Values that equal the current one are skipped. Each change is audited as `setting.changed` with `key`, `from` and `to`. Returns the same shape as `GET`, plus `changed` (the keys that changed).
- `DELETE /admin/settings/{key}` removes the override, so the `.env` default applies again. Audited as `setting.reset`. Returns the list. An unknown key is 404.

Changes apply to the next request at once, and queue workers restart after their current job. Settings change future behaviour only: for example a new holding period applies to orders paid afterwards. `GET /store/affiliate-programme` and the other endpoints that report these figures show the value in force.

### 11.4 Product photos and categories

All need `catalog.manage`.

- `POST /admin/products/{product}/image` as `multipart/form-data` with one field, `image`: JPEG, PNG or WebP, detected from the content, up to 5 MB and at most 6,000 pixels on each side. Anything else gets 422 on `image`. It replaces any earlier photo, whose file is deleted. Returns `{"data": Product}`. Audited as `product.image_changed`.
- `DELETE /admin/products/{product}/image` removes the photo and its file. Audited as `product.image_removed`.
- Products now carry `image_url` (in the store and admin APIs), or `null`. The address is `https://<host>/media/products/<ULID>.<ext>`, a public web route outside `/api`, with no session or sign-in. Each upload gets a new name, so responses are cached for a year (`Cache-Control: public, max-age=31536000, immutable`). Only files a product currently uses are served; anything else is 404.
- `GET /admin/categories` now includes hidden categories and `products_count`.
- `PATCH /admin/categories/{category}` with `name` and/or `is_active`. Sending `slug` gets 422: the slug never changes, so shared shop links keep working. A hidden category is left out of `GET /store/categories`; its products stay in the store. Audited as `product_category.updated` in the same transaction, and only when something changed.
- `DELETE /admin/categories/{category}` returns 204. It is refused with 422 on `category` while the category has products, past order lines or a commission rule. Deleted and audited as `product_category.deleted` in one transaction.

## 12. Examples

```bash
API=https://store.example/api/v1
```

**Sign in and get a token**

```bash
curl -s -X POST "$API/auth/login" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"asha@example.com","password":"Correct-Horse-9","device_name":"asha-android"}'
# {"token":"12|q8Zt...","token_type":"Bearer","user":{"id":"01JA...","email_verified":true,...}}
TOKEN='12|q8Zt...'
```

**List your own commissions (affiliate, `affiliate.portal`)**

```bash
curl -s "$API/affiliate/earnings/commissions?status=available&page=1" \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
# {"data":[{"id":"01JB...","order_number":"…Q2MX","amount_minor":15000,"net_minor":15000,
#   "currency":"NPR","status":"available",...}],"links":{...},"meta":{"per_page":25,...}}
```

**Place an order with an Idempotency-Key (guest)**

```bash
KEY=$(uuidgen)
curl -s -X POST "$API/store/orders" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $KEY" \
  -d '{"items":[{"product_id":"01JA6Y0C3QF7H1V9X2M4N8P5RT","quantity":2}],
       "customer_name":"Ram Shrestha","customer_email":"ram@example.com",
       "customer_phone":"+9779812345678","shipping_city":"Kathmandu",
       "shipping_address":"Baneshwor-10","coupon_code":"ASHA10"}'
# 201 {"data":{"id":"01JC...","order_number":"NFC-261012-…","status":"pending_payment",
#      "total_minor":270000,"currency":"NPR",...},"meta":{"access_token":"x7Kp...48 chars"}}
# Same key + same body again → 200 with the same order; different body → 409.
```

**Start an eSewa payment and poll its status**

```bash
ORDER=01JC...; ACCESS='x7Kp...'
curl -s -X POST "$API/store/orders/$ORDER/payments" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"provider\":\"esewa\",\"access_token\":\"$ACCESS\"}"
# 201 {"data":{"attempt_id":"01JD...","redirect":{"type":"form","url":"https://…esewa…/form",
#      "fields":{"amount":"…","transaction_uuid":"…","signature":"…",...}}}}
# → POST those fields from a browser/webview, then poll:
curl -s "$API/store/orders/$ORDER/payment-status?access_token=$ACCESS" -H 'Accept: application/json'
# {"data":{"order_status":"paid","order_status_label":"Paid","paid":true,
#          "attempt":{"provider":"esewa","status":"completed","outcome":"paid"}}}
```

**Staff: confirm a manual payment (`payments.confirm`)**

```bash
curl -s -X POST "$API/admin/orders/$ORDER/payments" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -d '{"method":"bank_transfer","reference":"NIBL-20261012-4471","amount_minor":270000,"note":"Seen on statement"}'
# 201 order detail; a repeat with the same reference → 200 (nothing changed)
```

**Export a report (`reports.export` plus the report's own permission; 10/min; audited)**

```bash
curl -s -G "$API/admin/reports/commission-accruals/export" \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  --data-urlencode format=csv --data-urlencode from=2026-09-01 --data-urlencode to=2026-09-30 \
  -o commission-accruals.csv
# Affiliates: GET /affiliate/reports/my-statement/export?format=xlsx
```

## 13. Endpoint index

Paths are relative to `/api/v1`. Request bodies and response fields are in [`openapi.yaml`](api/openapi.yaml).

| Area | Endpoints |
|---|---|
| Auth (public) | `POST auth/register`, `POST auth/login`, `POST auth/forgot-password`, `POST auth/reset-password`, `GET auth/verify-email/{user}/{hash}` |
| Auth (signed in) | `GET/PATCH auth/me`, `POST auth/password`, `POST auth/logout`, `POST auth/email/verification-notification` |
| Notifications | `GET notifications`, `POST notifications/{id}/read`, `POST notifications/read-all` |
| Store (public) | `GET store/products`, `GET store/products/{slug}`, `GET store/categories`, `GET store/referral`, `GET store/affiliate-programme`, `POST store/cart/quote`, `POST store/orders` |
| Store payments | `GET store/payment-methods`, `POST store/orders/{order}/payments`, `GET store/orders/{order}/payment-status` |
| Customer orders | `GET orders`, `GET orders/{order}` |
| Affiliate membership | `GET affiliate/terms/current`, `POST affiliate/terms/accept`, `POST affiliate/applications`, `GET/PATCH affiliate/profile`, `POST affiliate/profile/close` |
| Affiliate portal | `GET/POST affiliate/links`, `PATCH affiliate/links/{link}`, `GET affiliate/campaigns`, `GET affiliate/marketing-assets`, `GET affiliate/marketing-assets/{asset}/file`, `GET affiliate/referrals/{summary,orders}`, `GET affiliate/earnings/{summary,commissions,ledger,rates}`, `GET affiliate/dashboard`, `GET affiliate/reports`, `GET affiliate/reports/{report}/export` |
| Affiliate payouts | `GET affiliate/payouts/summary`, `GET/POST affiliate/payouts`, `POST affiliate/payouts/{payout}/cancel`, `PUT affiliate/payout-method` |
| Admin: affiliates and terms | `GET admin/affiliates`, `GET admin/affiliates/counts`, `GET admin/affiliates/{affiliate}`, `POST admin/affiliates/{affiliate}/{approve,reject,notes,suspend,reinstate,deactivate,reactivate}`, `GET/POST admin/terms` |
| Admin: catalog and coupons | `GET/POST admin/products`, `PATCH admin/products/{product}`, `POST/DELETE admin/products/{product}/image`, `GET/POST admin/categories`, `PATCH/DELETE admin/categories/{category}`, `GET/POST admin/coupons`, `PATCH admin/coupons/{coupon}` |
| Admin: staff and settings | `GET/POST admin/staff`, `POST admin/staff/{user}/invitation`, `POST admin/staff/{user}/role`, `POST admin/staff/{user}/status`, `GET/PATCH admin/settings`, `DELETE admin/settings/{key}` |
| Admin: campaigns and marketing | `GET/POST admin/campaigns`, `GET/PATCH admin/campaigns/{campaign}`, `GET/POST admin/marketing-assets`, `PATCH/DELETE admin/marketing-assets/{asset}`, `GET admin/marketing-assets/{asset}/file` |
| Admin: orders and payments | `GET admin/orders`, `GET admin/orders/{order}`, `POST admin/orders/{order}/{cancel,attribution,payments,refunds,fulfilment,disputes}`, `POST admin/disputes/{dispute}/resolve`, `GET admin/payment-attempts`, `POST admin/payment-attempts/{attempt}/{recheck,resolve}` |
| Admin: commissions | `GET/POST admin/commission-rules`, `GET admin/commission-rules/preview`, `PATCH admin/commission-rules/{rule}`, `GET/POST admin/commission-plans`, `PATCH admin/commission-plans/{plan}`, `POST admin/affiliates/{affiliate}/plan`, `GET admin/affiliates/{affiliate}/ledger`, `GET admin/commissions`, `GET admin/commissions/summary`, `GET admin/commissions/{commission}`, `POST admin/commissions/{commission}/{hold,release-hold,void,release}`, `GET admin/fraud-flags`, `POST admin/fraud-flags/{flag}/review`, `GET/POST admin/adjustments`, `POST admin/adjustments/{adjustment}/{approve,reject}`, `GET/POST admin/reconciliation` |
| Admin: payouts | `GET admin/payouts`, `GET admin/payouts/summary`, `GET admin/payouts/{payout}`, `POST admin/payouts/{payout}/{approve,reject,mark-paid,mark-failed}` |
| Admin: dashboards and reports | `GET admin/dashboard`, `GET admin/dashboard/filter-options`, `GET admin/finance/dashboard`, `GET admin/reports`, `GET admin/reports/{report}`, `GET admin/reports/{report}/export`, `GET admin/referrals/summary`, `GET admin/audit-logs`, `GET admin/ping` |
| Web (not API) | `GET /ref/{code}/{link?}` (referral redirect, records a click and sets the `nfc_ref` cookie); `GET /media/products/{file}` (product photos); `/payments/esewa/return`, `/payments/esewa/failure/{attempt}`, `/payments/khalti/return` (providers only) |
