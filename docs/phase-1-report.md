# Phase 1 report: Foundation

Date: 2026-10-09 · Status: **complete, verified in a test environment**

## What was built

| Item | Where |
|---|---|
| Laravel 12.69 app, PHP 8.3, MySQL 8 configuration, `.env.example` with placeholders only | root |
| Business defaults (NPR, Asia/Kathmandu, 30-day window, 14-day hold, NPR 1,000 payout minimum) | `config/affiliate.php` |
| Register, login, logout, me, email verification, resend, forgot and reset password | `app/Http/Controllers/Api/V1/Auth` |
| Session auth for the web app, bearer tokens for API clients | Sanctum, [ADR 0004](adr/0004-auth-session-and-tokens.md) |
| 6 roles, 26 permissions, idempotent seeder, generated matrix document | `PermissionMatrix`, [permissions-matrix.md](permissions-matrix.md) |
| First-admin bootstrap with no default password (interactive or `--password-stdin`) | `php artisan affiliate:create-admin` |
| Append-only audit log with secret redaction and hashed IPs, plus a staff viewer | `AuditLogger`, `/api/v1/admin/audit-logs` |
| JSON errors on every API route, no details when debug is off | `bootstrap/app.php` |
| Rate limits on login, register and password reset | `AppServiceProvider` |
| Security headers, HSTS and CSP in production, forced HTTPS | `SecurityHeaders` |
| Disabled accounts blocked even with a valid token | `EnsureUserIsActive` |
| React 19 + TypeScript web app: login, register, verify email, forgot/reset password, account area, staff area with audit log, mobile navigation | `resources/js` |
| Optional Docker Compose (MySQL 8 and Mailpit), GitHub Actions CI | `docker-compose.yml`, `.github/workflows/ci.yml` |
| Docs: architecture, 5 ADRs, OpenAPI 3.1, Windows setup | `docs/` |

## Verification (actual results)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | 55 passed, 188 assertions |
| `php artisan test` on MySQL 8.0.46 | 55 passed, 188 assertions |
| `php artisan migrate --seed`, `migrate:rollback`, re-migrate on MySQL 8 | succeeded |
| `npm run typecheck` | no errors |
| `npm run lint` | no errors |
| `npm run build` | succeeded |
| `vendor/bin/pint --test` | passed |
| `composer audit` | no advisories |
| `npm audit` | 0 vulnerabilities |
| OpenAPI spec (Redocly lint) | valid |
| Browser test in Chromium against `php artisan serve` with MySQL | passed (below) |

The browser test went through these steps:
1. Registered at phone width.
2. The verification email was sent through the database queue worker.
3. Opened the signed link and saw "Email verified".
4. Landed on `/account`.
5. Opening `/admin` redirected back to the account area, and calling the admin API directly returned 403.
6. Logged out.
7. Entered a wrong password and saw the error message.
8. Signed in as the bootstrapped admin and saw the real audit log entries.

These were run in a Linux cloud container, not yet on your Windows machine. The CI workflow has not run yet because the code is not on GitHub yet.

### Security tests included

- Customers, affiliates and users with no role get 403 on staff routes. Support, finance and affiliate managers can reach only what the matrix allows.
- Unverified staff are blocked from staff routes.
- Registration ignores `role`, `status`, `email_verified_at` and `public_id` sent by the client (mass assignment).
- Login does not reveal whether an email exists. Forgot-password gives the same answer either way.
- Tampered, expired or wrong-hash verification links are rejected.
- A reset token works only once, and a reset revokes all API tokens.
- Audit entries cannot be updated or deleted, and secrets are redacted.
- Server errors do not leak messages, SQL or secrets when debug is off.
- API responses never include the internal id, password or remember token.
- The production seeder creates no users.

## Acceptance criteria (Phase 1)

| Criterion | Met |
|---|---|
| Fresh checkout: `composer install`, `migrate --seed` and `test` pass | yes (MySQL and SQLite) |
| `npm run build` and `npm run typecheck` pass | yes |
| A user can register, verify email and log in | yes (feature tests and browser test) |
| Admin from the bootstrap command reaches `/api/v1/admin/ping`, a customer gets 403 | yes |
| No secrets in the repository | yes (`.env` is gitignored, `.env.example` has placeholders) |

## Differences from the Phase 0 plan

See [ADR 0005](adr/0005-phase-1-tooling-choices.md): PHPUnit instead of Pest, React Router 7 because v6 has open advisories, and a signed GET link for email verification.

## Not done yet / known limitations

- Optional admin MFA (TOTP) is planned for Phase 8.
- Account lockout beyond rate limiting, and alerts on repeated failed logins, are planned for Phase 8 (fraud signals).
- The production database user should have only `INSERT, SELECT` on `audit_logs`. This is documented but has to be set on the server (Phase 9 deployment guide).
- The affiliate programme landing page and privacy notice text need business and legal input.
- No real email provider is configured. Local development uses the log mailer or Mailpit.

## Next: Phase 2, affiliate onboarding

1. Affiliate application form and states: draft, pending, approved, rejected, suspended, deactivated.
2. Recorded state transitions with history.
3. Staff review with internal notes.
4. Notifications to the applicant.
5. Terms acceptance history.
6. Affiliate profile with referral code and slug.
