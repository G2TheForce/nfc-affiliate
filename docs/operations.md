# Operations runbook

For whoever runs the server. Business tasks (reviewing affiliates, confirming payments, paying payouts) are in the [administrator guide](admin-guide.md). Installation and upgrades are in [deployment.md](deployment.md).

## What must be running

| Piece | Check | If it stops |
|---|---|---|
| Web (nginx + PHP-FPM) | `curl -fsS https://<domain>/up` returns 200 | The shop and portals are down |
| Queue worker | `sudo supervisorctl status affiliate-worker` shows RUNNING | No emails or in-app notices go out; they wait in the `jobs` table and send once it restarts |
| Scheduler | `php artisan schedule:list`; cron line present for the deploy user | Unfinished online payments are not re-checked; commissions stop maturing; no nightly reconciliation |
| MySQL | `mysqladmin ping` | Everything stops |

With Docker: `docker compose -f compose.production.yml ps` should show five healthy services.

## Daily (5 minutes)

1. Open the **Finance dashboard**. The **Reconciliation** card should say Clean, last checked today.
2. Check **Online payments** for anything waiting for review.
3. Run `php artisan queue:failed`. It should be empty. See "Failed jobs" below.
4. Search for errors in the log: `grep -E "\.(ERROR|CRITICAL|ALERT|EMERGENCY)" storage/logs/laravel-$(date +%F).log`.
5. Check that last night's backup file exists and was copied off the server.

## Weekly

- `df -h`: disk space on the database, logs and backup volumes.
- `composer audit` and `npm audit --omit=dev` on the deployed version (CI also runs them on every change).
- Run `sudo certbot renew --dry-run` once a month.

## Quarterly

- A restore drill into a scratch database, as in [backup-and-restore.md](backup-and-restore.md), ending with `affiliate:reconcile-commissions` showing clean.
- Review staff accounts. Disable anyone who has left (below).
- Check that `APP_KEY` in the password manager still matches the server.

## Staff accounts

```bash
php artisan affiliate:create-staff --role=finance_manager     # or affiliate_manager, support
php artisan affiliate:create-admin --additional                # another Super Administrator
php artisan affiliate:set-user-status person@example.com disabled --reason="Left the company"
php artisan affiliate:set-user-status person@example.com active --reason="Returned"
```

Disabling signs the person out everywhere: their API tokens and sessions are deleted, and every request is refused while the account is disabled. The last active Super Administrator cannot be disabled. A staff member can be given a different role only by disabling the old account and creating a new one, so that the audit log keeps who did what under which role.

## Common problems

### Emails are not arriving

1. `supervisorctl status`: is the worker running? If not, `supervisorctl start affiliate-worker`.
2. `php artisan queue:failed`: look at the error. A mail provider problem shows as an SMTP or connection error.
3. Fix the cause, then `php artisan queue:retry all`. Each notification already retries 5 times over about 20 minutes before failing.
4. After changing `.env`: `php artisan config:cache` and `php artisan queue:restart`.

### Failed jobs

`php artisan queue:failed` lists them, `queue:retry <id>` or `queue:retry all` retries, and `queue:forget <id>` removes one. Failed jobs older than 30 days are pruned automatically. Jobs only send notifications. Money changes are made before the job is queued, so a failed job never means lost money.

### The scheduler seems stuck

- `php artisan schedule:list` shows when each job runs next.
- A job killed mid-run (reboot, deploy) holds its lock for at most 10 to 120 minutes. `php artisan schedule:clear-cache` releases the locks immediately.
- Run a job by hand to see its output: `php artisan payments:recheck`, `affiliate:mature-commissions`, `affiliate:reconcile-commissions`.

### A customer paid but the order still says Awaiting payment

1. Open the order and, under **Online payments**, use **Check with eSewa now** (or Khalti).
2. If the provider says it is paid, the order turns Paid. If the amount or status does not match, it goes to the review queue. Decide there after checking the eSewa or Khalti merchant portal.
3. Never mark an online payment paid without seeing it in the provider's portal. Manual confirmation needs the provider's reference, and each reference can be used once.

### eSewa or Khalti is down

Customers see that the provider is not responding, and the order stays Awaiting payment; nothing is charged. To stop offering that provider, set `ESEWA_ENABLED=false` (or `KHALTI_ENABLED=false`), then run `php artisan config:cache`. Attempts already started are still re-checked every five minutes.

### Reconciliation reports a problem

Reconciliation compares the ledger with commissions, adjustments and payouts. It never fixes anything itself. Finance gets an email and the dashboard shows the details. Do not edit the database by hand. Find the commission or payout it names, and correct it with an adjustment (two people) or the payout actions, then run `php artisan affiliate:reconcile-commissions` again. If the cause is a code bug, keep the report and raise it with the developer.

### Disk full

Delete old files in `storage/logs` and old backups that are already copied off the server, then find the cause. Common causes are a log level left at `debug`, or a backup copy job that stopped. MySQL needs free space to finish writes, so act quickly.

### Suspected break-in or leaked password

1. Disable the affected staff account (above). A password reset also revokes all of that person's tokens and sessions.
2. Check the **Audit log** for what the account did, from which (hashed) address and when.
3. If `.env` may have leaked, rotate in this order:
   - database passwords
   - mail credentials
   - eSewa and Khalti keys, in their merchant portals
   - `APP_KEY` last
4. Rotating `APP_KEY` makes saved payout details unreadable, because they are encrypted with it. Before rotating it, ask the developer to re-encrypt them, or have affiliates re-enter their details.

### Restoring from a backup

Follow [backup-and-restore.md](backup-and-restore.md). Restore into a new database, check it with reconciliation, then switch `.env` to it during maintenance mode. Afterwards, compare eSewa and Khalti payments since the backup by hand.

## Logs

| Log | Where |
|---|---|
| Application | `storage/logs/laravel-YYYY-MM-DD.log` (kept for `LOG_DAILY_DAYS` days) |
| Queue worker | `storage/logs/worker.log` (supervisor) |
| Backups | `storage/logs/backup.log` |
| nginx | `/var/log/nginx/` |
| Audit trail of business actions | **Audit log** page (Super Administrator), `audit_logs` table |

Logs contain record IDs, not passwords, tokens, bank details or raw IP addresses.
