# Production configuration

Settings to review before going live. Full deployment steps come with Phase 9.

## `.env`

| Key | Value | Why |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | Generic errors; turns on the CSP, HSTS and forced HTTPS |
| `APP_KEY` | generated once with `php artisan key:generate`, then backed up | Encrypts payout details and sessions; losing it makes saved bank details unreadable |
| `APP_URL`, `FRONTEND_URL` | `https://<domain>` | Payment providers return customers here; email links use it |
| `LOG_STACK` / `LOG_LEVEL` / `LOG_DAILY_DAYS` | `daily` / `warning` / `30` | Logs rotate and stay small |
| `DB_USERNAME` | an app user without DDL rights | Run migrations with a separate user. Optionally deny `UPDATE` and `DELETE` on `audit_logs` and `commission_ledger_entries` to the app user. |
| `SESSION_ENCRYPT` / `SESSION_SECURE_COOKIE` / `SESSION_SAME_SITE` | `true` / `true` / `lax` | Secure is now the production default even if omitted |
| `SANCTUM_STATEFUL_DOMAINS` | `<domain>,www.<domain>` (no localhost) | Only your site can use the session cookie |
| `SANCTUM_EXPIRATION` | `43200` (30 days, in minutes) | Mobile and API tokens expire |
| `TRUSTED_PROXIES` | the load balancer's IP or CIDR, or `*` when the app is reachable only through the proxy | Real client IPs for rate limits and audit; HTTPS detection for HSTS |
| `QUEUE_CONNECTION` / `CACHE_STORE` | `database` (or `redis`) | The cache must be shared by all app servers, because scheduler locks and rate limits use it |
| `MAIL_*` | your provider, `MAIL_SCHEME=smtps` | Receipts, shipping and finance alerts |
| `ESEWA_*` / `KHALTI_*` | `*_MODE=production` and production keys, `*_ENABLED=true` only when tested | Sandbox mode is refused in production |
| `AFFILIATE_BUSINESS_TIMEZONE` / `AFFILIATE_CURRENCY` | `Asia/Kathmandu` / `NPR` | |

## Processes

- **Scheduler:** one cron line per server: `* * * * * cd /var/www/affiliate && php artisan schedule:run >> /dev/null 2>&1`. Jobs run on one server and recover from a killed run within 10 to 120 minutes. `php artisan schedule:clear-cache` clears a stuck lock immediately.
- **Queue worker** (under supervisor or systemd): `php artisan queue:work --tries=3 --timeout=60 --max-time=3600`. Run `php artisan queue:restart` on every deploy.
- **Failed jobs:** check with `php artisan queue:failed`, retry with `queue:retry all`. Failed jobs older than 30 days are pruned daily.
- **Caches:** on each deploy, run `php artisan config:cache`, `route:cache`, `view:cache` and `event:cache`. All four were checked to work: there are no closures in routes, and no `env()` outside config files.
- **PHP:** opcache on. Only `storage/` and `bootstrap/cache` writable by the web user. `.env` mode 640.
