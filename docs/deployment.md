# Production deployment

How to put release 1.0.0 on a server, keep it running, upgrade it and roll it back. There are two ways to run it:

- **A Linux server** with nginx, PHP-FPM, MySQL, supervisor and cron (sections 1 to 12).
- **Docker Compose** with the same pieces in containers (section 13).

Settings are explained in [production-config.md](production-config.md), backups in [backup-and-restore.md](backup-and-restore.md), and day-to-day checks in [operations.md](operations.md).

## 1. Software versions

| Software | Version | Tested with |
|---|---|---|
| PHP | 8.3 or 8.4, with `pdo_mysql`, `mbstring`, `xml`, `zip`, `intl` (optional), `pcntl`, `opcache` | 8.3 |
| MySQL | 8.0 or later, InnoDB, `utf8mb4` | 8.0.46 |
| Composer | 2.x | 2.8 |
| Node.js | 20 or 22 (build only; not needed on the server if you build elsewhere) | 22 |
| nginx | any current version | 1.27 |
| supervisor | any (or systemd) | |
| OS | any current Linux; commands below are for Ubuntu 24.04 | |

Laravel 12.x, Sanctum 4 and spatie/laravel-permission 8 come from `composer.lock`.

## 2. Install

```bash
sudo apt install nginx mysql-server supervisor php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-zip php8.3-intl php8.3-curl unzip git
# Composer: https://getcomposer.org/download/   Node 22: https://nodejs.org/
sudo mkdir -p /var/www/affiliate && sudo chown deploy:www-data /var/www/affiliate
git clone git@github.com:G2TheForce/nfc-affiliate.git /var/www/affiliate
cd /var/www/affiliate
git checkout v1.0.0            # or the release commit
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build        # creates public/build; node_modules is not needed afterwards
```

A clean install was verified on 2026-10-12 from a fresh clone: `composer install`, `npm ci`, `migrate --seed`, `affiliate:create-admin`, `npm run build`, the four cache commands, `/up`, sign-in and the staff dashboard all worked against an empty MySQL 8 database.

## 3. Database

Create the database and two users: one that can change the schema (used only for migrations) and one with data rights only (used by the app).

```sql
CREATE DATABASE affiliate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'affiliate_migrate'@'localhost' IDENTIFIED BY '<long random password>';
GRANT ALL PRIVILEGES ON affiliate.* TO 'affiliate_migrate'@'localhost';

CREATE USER 'affiliate_app'@'localhost' IDENTIFIED BY '<another long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON affiliate.* TO 'affiliate_app'@'localhost';

-- Optional hardening: the audit log and commission ledger are append-only.
-- MySQL cannot revoke a table from a schema-wide grant, so grant per table instead
-- if you want this (list tables with SHOW TABLES and grant INSERT, SELECT only on these two).

CREATE USER 'affiliate_backup'@'localhost' IDENTIFIED BY '<third password>';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES ON affiliate.* TO 'affiliate_backup'@'localhost';
```

Keep MySQL listening on localhost or a private network only (`bind-address = 127.0.0.1`).

## 4. Environment file

```bash
cp .env.example .env
chmod 640 .env && chown deploy:www-data .env
php artisan key:generate
```

Fill in `.env` using the table in [production-config.md](production-config.md). The minimum:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domain>
FRONTEND_URL=https://<domain>
DB_CONNECTION=mysql
DB_DATABASE=affiliate
DB_USERNAME=affiliate_app
DB_PASSWORD=<app password>
LOG_STACK=daily
LOG_LEVEL=warning
SANCTUM_STATEFUL_DOMAINS=<domain>,www.<domain>
TRUSTED_PROXIES=           # set if behind a load balancer or Cloudflare
MAIL_MAILER=smtp           # plus MAIL_* from your mail provider
```

**Back up `APP_KEY` now**, in a password manager, separately from the database backups. Payout bank and wallet details are encrypted with it; without it they cannot be read.

Leave `ESEWA_ENABLED` and `KHALTI_ENABLED` off until the production keys are in and a small real payment has been tried (section 11).

## 5. Migrations, roles and the first administrator

```bash
DB_USERNAME=affiliate_migrate DB_PASSWORD='<migrate password>' php artisan migrate --force --seed
php artisan affiliate:create-admin
```

`--seed` creates roles and permissions only; production gets no sample data and no commission rules. Then create the other staff accounts:

```bash
php artisan affiliate:create-staff --role=finance_manager
php artisan affiliate:create-staff --role=affiliate_manager
php artisan affiliate:create-staff --role=support
```

Each command asks for a name, email and password (at least 12 characters with mixed case and a number). See [migrations.md](migrations.md) for what each migration does.

## 6. File permissions and caches

```bash
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

Only `storage/` and `bootstrap/cache/` need to be writable by the web user.

## 7. Queue worker (supervisor)

`/etc/supervisor/conf.d/affiliate-worker.conf`:

```ini
[program:affiliate-worker]
command=php /var/www/affiliate/artisan queue:work --tries=3 --timeout=60 --max-time=3600 --sleep=3
directory=/var/www/affiliate
user=deploy
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/var/www/affiliate/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

The worker sends every email and in-app notice. One process is enough at launch size.

## 8. Scheduler (cron)

```bash
sudo crontab -u deploy -e
* * * * * cd /var/www/affiliate && php artisan schedule:run >> /dev/null 2>&1
```

What it runs (times are Nepal time where stated):

| Job | When |
|---|---|
| `payments:recheck` (unfinished eSewa/Khalti payments) | every 5 minutes |
| `affiliate:mature-commissions` (approve and release after the holding period) | hourly |
| `affiliate:prune-referral-data` (click retention) | 03:15 daily |
| `affiliate:reconcile-commissions` (ledger check, emails finance on a problem) | 03:30 daily |
| `sanctum:prune-expired`, `queue:prune-failed` | daily |

`php artisan schedule:list` shows the next run of each.

## 9. Web server (nginx)

`/etc/nginx/sites-available/affiliate`:

```nginx
server {
    listen 80;
    server_name <domain> www.<domain>;
    root /var/www/affiliate/public;
    index index.php;
    client_max_body_size 25m;
    server_tokens off;

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/affiliate /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

The app sets its own security headers (CSP, HSTS, frame denial and others), so nginx does not need to add them. In `/etc/php/8.3/fpm/php.ini` set `expose_php = Off`, `upload_max_filesize = 24M` and `post_max_size = 25M` (marketing uploads are limited to 20 MB by `AFFILIATE_MARKETING_MAX_UPLOAD_KB`), and turn on opcache (`opcache.enable=1`, `opcache.validate_timestamps=0`; reload PHP-FPM on each deploy).

## 10. HTTPS

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d <domain> -d www.<domain> --redirect
sudo certbot renew --dry-run     # renewal runs from a systemd timer
```

In production the app forces `https` links and sends HSTS once it sees HTTPS. Behind Cloudflare or a load balancer that ends TLS, set `TRUSTED_PROXIES` to its addresses, or `*` if the server accepts traffic only from it; otherwise HSTS is never sent and every visitor shares one IP in rate limits and the audit log.

## 11. Payment providers

1. Put the production keys in `.env` only: `ESEWA_MODE=production`, `ESEWA_PRODUCT_CODE`, `ESEWA_SECRET_KEY`; `KHALTI_MODE=production`, `KHALTI_SECRET_KEY`. Never commit them.
2. `php artisan config:cache`.
3. Turn on one provider at a time (`ESEWA_ENABLED=true`), make a small real payment, and check that the order turns Paid by itself, the receipt email arrives and the attempt shows as completed under **Online payments**.
4. Refunds are made in the provider's merchant portal first and then recorded in the order.

The providers must reach `APP_URL` over HTTPS, because they send the customer's browser back there.

## 12. Logs, monitoring and backups

**Log rotation.** With `LOG_STACK=daily` Laravel rotates its own log and keeps `LOG_DAILY_DAYS` files (30 suggested). Rotate the worker and backup logs with logrotate, `/etc/logrotate.d/affiliate`:

```
/var/www/affiliate/storage/logs/worker.log /var/www/affiliate/storage/logs/backup.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

**Monitoring.** At minimum:

- An uptime check on `https://<domain>/up` (returns 200 when the app boots).
- An alert when `storage/logs/laravel-*.log` gets `ERROR` or `CRITICAL` lines (any log shipper, or a daily `grep`).
- `php artisan queue:failed` should be empty; `supervisorctl status` should show the worker RUNNING.
- The nightly reconciliation emails finance when the ledger and commissions disagree, and the finance dashboard shows the last run.
- Disk space on the database and backup volumes.

**Backups.** Set up `scripts/backup-db.sh` from cron and copy each file off the server, as described in [backup-and-restore.md](backup-and-restore.md). Run a restore drill before launch and every quarter.

## 13. Docker Compose

`compose.production.yml` runs the whole system in five containers, each with a health check:

| Service | What it runs | Health check |
|---|---|---|
| `web` | nginx serving the built frontend, passing PHP requests to `app` | `GET /up` |
| `app` | PHP-FPM with the application | FPM port open |
| `queue` | `queue:work` (same image) | worker process running |
| `scheduler` | `schedule:work` (same image) | scheduler process running |
| `mysql` | MySQL 8.0, data in a named volume | `mysqladmin ping` |

Only `web` publishes a port, on `127.0.0.1:8080` by default (`WEB_BIND`, `WEB_PORT`). MySQL is not published at all. Put an HTTPS reverse proxy in front (host nginx with certbot as in section 10 proxying to `127.0.0.1:8080`, or Caddy), and set `TRUSTED_PROXIES` accordingly. `storage/` lives in the `app-storage` volume and the database in `mysql-data`.

```bash
cp .env.example .env    # fill it in as in section 4, and add DB_ROOT_PASSWORD
# APP_KEY: generate once with a local PHP (php artisan key:generate --show) and paste it in
docker compose -f compose.production.yml build
docker compose -f compose.production.yml up -d mysql
docker compose -f compose.production.yml run --rm app php artisan migrate --force --seed
docker compose -f compose.production.yml run --rm app php artisan affiliate:create-admin
docker compose -f compose.production.yml up -d
docker compose -f compose.production.yml ps        # all five should say healthy
```

The containers build Laravel's caches when they start. Migrations are never run automatically. Logs go to `storage/logs` in the volume when `LOG_CHANNEL=stack`, or to `docker compose logs` with `LOG_CHANNEL=stderr`. Back up with `docker compose -f compose.production.yml exec mysql mysqldump ...` or run `scripts/backup-db.sh` inside the `app` container (it includes the MySQL client).

Verified on 2026-10-12: the images built, all five services became healthy, migrations and the first administrator worked through `run --rm app`, and sign-in and the staff dashboard worked. Static assets were served with long-lived cache headers, `/.env` was refused, and a queued email was sent by the worker. The scheduler ran `payments:recheck`, and data survived `down` and `up`. In that test, the base PHP image was swapped for one with the extensions preinstalled, because the test machine could not reach the Alpine package mirrors. The Dockerfile itself installs any missing extensions.

## 14. Upgrading

Every release:

```bash
cd /var/www/affiliate
scripts/backup-db.sh                                   # fresh backup first
php artisan down --retry=60
git fetch --tags && git checkout <new tag>
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
DB_USERNAME=affiliate_migrate DB_PASSWORD='<migrate password>' php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
sudo systemctl reload php8.3-fpm
php artisan queue:restart                              # supervisor starts the worker on the new code
php artisan up
```

Then open `/up`, sign in, and check the finance dashboard. Read the release notes ([CHANGELOG.md](../CHANGELOG.md)) first: they say whether a migration changes or removes data.

With Docker: `git checkout <tag>`, `docker compose -f compose.production.yml build`, `run --rm app php artisan migrate --force`, then `up -d`.

## 15. Rolling back

1. `php artisan down`.
2. `git checkout <previous tag>`, then `composer install --no-dev ...` and `npm ci && npm run build`.
3. Database:
   - If the release added only new tables or indexes (true for every migration up to 1.0.0), the old code runs on the new schema. Leave the database as it is.
   - Otherwise, run `php artisan migrate:rollback --step=<number of new migrations>` as the migrate user. Or restore the backup you took before upgrading, into a new database, and point `.env` at it as in [backup-and-restore.md](backup-and-restore.md). Restoring loses anything done since the backup, so compare eSewa and Khalti for that window by hand.
4. Rebuild the caches, reload PHP-FPM, `php artisan queue:restart`, `php artisan up`.
