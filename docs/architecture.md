# Architecture

This describes what is built now (Phase 1). The full target design is in [phase-0-plan.md](phase-0-plan.md).

## Overview

One Laravel 12 application serves both:

- the JSON API under `/api/v1` (all business logic), and
- the React single-page app (every other path returns `resources/views/app.blade.php`).

The web app uses only the public API, so the same endpoints serve a future Flutter app.

```
Browser (React SPA) ──cookie session──┐
                                      ├──> /api/v1 (Laravel) ──> MySQL 8
Mobile/API client ──bearer token──────┘          │
                                                 └──> queue (database driver) ──> email
```

## Authentication

- **Web app:** Sanctum stateful session. The SPA fetches `/sanctum/csrf-cookie` once and sends `X-XSRF-TOKEN` on writes. Login and register without `device_name` start a session.
- **API clients:** send `device_name` to `/auth/login` or `/auth/register` and receive a bearer token. Logout deletes that token. A password reset deletes all tokens.
- **Email verification:** a signed, 60-minute link to `GET /api/v1/auth/verify-email/{public_id}/{hash}`, which redirects to the SPA's `/email-verified` page.
- **Password reset:** the emailed link opens the SPA's `/reset-password` page, which posts to the API. The forgot-password endpoint answers the same way whether or not the account exists.
- Verification and reset emails are queued notifications with retries, so a mail outage never blocks a request.

## Authorization

- Roles and permissions are defined once in `app/Support/Authorization/PermissionMatrix.php` and seeded idempotently by `RolesAndPermissionsSeeder` (safe to run on every deploy; it also removes permissions added by hand).
- Staff routes require `auth:sanctum`, `active`, `verified`, `permission:admin.access`, and then a specific permission per route.
- The SPA hides navigation the user lacks permission for, but only as a convenience. Tests check that the API refuses the call.

## Identifiers

Users have an internal auto-increment `id`, which never leaves the server, and a public ULID (`public_id`) used in URLs and API responses.

## Audit log

`audit_logs` is append-only. The model throws on update or delete, and in production the database user should have only `INSERT, SELECT` on that table. `AuditLogger` redacts keys that look like secrets (password, token, secret, signature, api key, account number, card) and stores IP addresses as an HMAC keyed with `APP_KEY`, not as the raw address.

Logged now: `auth.registered`, `auth.login`, `auth.login_failed`, `auth.logout`, `auth.email_verified`, `auth.password_reset`, `admin.bootstrap_created`.

## Errors

Every `/api/*` response is JSON, including 401, 403, 404, 422 and 500. With `APP_DEBUG=false`, server errors return `{"message":"Server Error"}` only, with no stack trace, SQL or configuration.

Validation errors use Laravel's standard shape:

```json
{ "message": "The email field is required.", "errors": { "email": ["The email field is required."] } }
```

## Rate limits

| Limiter | Limit |
|---|---|
| `login` | 5/min per email+IP (configurable), and 30/min per IP |
| `register` | 10/hour per IP |
| `password-reset` | 5/min per IP |
| verification link and resend | 6/min |

## Security headers

`SecurityHeaders` middleware sets `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` and COOP on every response. In production it also sets HSTS (over HTTPS) and a strict Content-Security-Policy, and `AppServiceProvider` forces `https` URLs.

## Configuration

Business defaults (timezone, currency, attribution window, holding period, payout minimum, retention) live in `config/affiliate.php` and are set from `.env`. Timestamps are stored in UTC. Business dates use `AFFILIATE_BUSINESS_TIMEZONE` (Asia/Kathmandu).

## Directory layout

```
app/Domain/Audit          AuditLogger
app/Enums                 Role, Permission, UserStatus
app/Support/Authorization PermissionMatrix
app/Http/Controllers/Api/V1/{Auth,Admin}
app/Http/Requests/Auth    Form Requests
app/Http/Resources        API Resources (public_id only, no secrets)
app/Notifications/Auth    Queued verification and reset emails
app/Console/Commands      affiliate:create-admin, affiliate:permissions-matrix
resources/js              React app (auth pages, account area, staff area)
docs/                     Plans, ADRs, API spec, guides
```
