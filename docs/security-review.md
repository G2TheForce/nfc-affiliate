# Security review (Phase 8)

Date: 2026-10-12. This covers the whole application at the end of Phase 8: 127 routes, every controller, the payment, commission and payout code, the configuration and the dependencies. It is an internal engineering review, not an external penetration test or a legal opinion.

## Findings and what was done

| # | Severity | Finding | Status |
|---|---|---|---|
| 1 | High | The production Content-Security-Policy (`form-action 'self'`) would have blocked every eSewa payment. The browser posts a form to eSewa, and the policy is only sent in production, so tests never saw it. | Fixed: the configured eSewa form origin is allowed. Test asserts the header. |
| 2 | High | `TRUSTED_PROXIES` was read with `env()` at boot, which returns nothing once config is cached. Behind a proxy, every visitor would share one IP for rate limits and audit logs, and HSTS would never be sent. | Fixed: read from `config('app.trusted_proxies')`. Test covers forwarded and spoofed addresses. |
| 3 | High (functional) | Single-use coupon race: 8 parallel checkouts redeemed a one-use code 4 times on MySQL. | Fixed with a locking read. Real parallel test ([load-and-concurrency.md](load-and-concurrency.md)). |
| 4 | Medium | A scheduled job killed mid-run (deploy, reboot) kept its overlap lock for 24 hours, silently stopping payment re-checks and maturation. | Fixed: locks expire after 10 to 120 minutes; jobs run on one server. |
| 5 | Medium | Five notifications, including the finance alerts, had no retries: one mail hiccup lost them. | Fixed: every queued notification retries 5 times with backoff and a 30 s timeout. Test checks all of them. |
| 6 | Medium | API tokens never expired. | Fixed: 30 days (`SANCTUM_EXPIRATION`); expired tokens pruned daily. |
| 7 | Medium | The session cookie was only `Secure` if `.env` said so. | Fixed: secure and encrypted by default in production. |
| 8 | Low | An affiliate's dashboard could show another affiliate's coupon code and name on an order credited to them by a click. Only with a non-default attribution setting. | Fixed, with a regression test. |
| 9 | Low | A provider "complete" status with an unreadable amount was accepted. | Fixed: goes to staff review. |
| 10 | Low | Staff could make a coupon over 100% by changing only its type. | Fixed: the cap checks the stored value. |
| 11 | Low | Array values in three inputs caused a server error instead of a refusal. | Fixed: refused cleanly. |
| 12 | Low | Login response time hinted which emails have accounts; there was no per-account limit across IPs. | Fixed: one hash check either way; 30 failures an hour per account. |
| 13 | Low | The general API rate limit was defined but never applied. | Fixed: 120/min per user or IP on every `/api` route. |
| 14 | Low | Payment return URLs stored any query values anyone sent. | Fixed: only short scalar values are stored. |
| 15 | Low | A disabled account could still start a payment on its own order. | Fixed. |

Every fix has a test that fails without it.

## Accepted for now, with reasons

- **Registration reveals that an email is taken.** Hiding it would mean emailing the existing owner instead of showing an error, which is a sign-up change for the business to decide. Login and password reset do not reveal it.
- **Staff can create API tokens.** They are limited by the same permissions and the `active` check, revoked on password reset or disablement, and now expire. Restricting staff to browser sessions is a reasonable later step.
- **Some summary figures follow broad permissions.** The staff dashboard shows commission totals to anyone with `reports.view`, and the finance dashboard is open to `payouts.view` or `commissions.view`. No current role is affected; revisit if custom roles are added.
- **No `config/cors.php`.** The framework default applies: any origin, no credentials. That is safe for token clients. Pin origins if a separate-origin web client is ever added.
- **Multi-factor authentication for administrators is not built.** The master prompt lists it as optional. It is the most valuable next security feature for staff accounts. Until it exists, use long unique passwords and keep the number of super-admins small.

## Requirement coverage (master prompt section 18)

| Requirement | Where |
|---|---|
| Password hashing, verification, reset protection | bcrypt (12 rounds in production); signed verification link; reset revokes tokens and sessions |
| Login abuse protection, auth rate limits | 5/min per email and IP, 30/min per IP, 30/hour per account |
| Secure sessions, CSRF | Encrypted, HttpOnly, Secure and SameSite=Lax cookies; Sanctum CSRF for the SPA; `AuthenticateSession` |
| Server-side authorization, IDOR | Permission middleware on every staff route; affiliate data always resolved from the signed-in user; ULIDs in URLs; 404 for others' records |
| SQL injection, XSS | Query builder with bindings only; no raw HTML rendering; escaped mail templates |
| Mass assignment | Money and state models use `$guarded = ['*']` with explicit `forceFill`; requests use validated data only |
| Open redirects | Referral destinations come from an allow-list; payment returns go to a relative path |
| File uploads and downloads | No upload endpoints yet. Exports need a permission, are streamed and audited, and are protected against formula injection. |
| Security headers, HTTPS | nosniff, frame denial, Referrer-Policy, Permissions-Policy and COOP everywhere. HSTS and a strict CSP in production; HTTPS forced in production. |
| Secret management, safe errors | `.env` only; production errors are generic JSON with no traces; logs carry IDs, not secrets or personal data |
| Payment provider signatures, replay, idempotency | [ADR 0011](adr/0011-payments-disputes-fulfilment.md): signature check, status API as the only proof, stored redirects, unique keys and row locks |
| Audit logging | Every money and access change, with redacted fields and hashed IPs |
| Dependency checks | `composer audit` and `npm audit --omit=dev`: no advisories (2026-10-12); both run in CI |
| Backup protection and recovery | [backup-and-restore.md](backup-and-restore.md): encrypted backups, tested restore |
| Least-privilege database user | [production-config.md](production-config.md) |
| Nepal legal and tax obligations | Listed for qualified local review in the [Phase 0 plan](phase-0-plan.md#nepal-legal-and-tax-notes-for-qualified-local-review-not-legal-advice). No compliance is claimed. |
