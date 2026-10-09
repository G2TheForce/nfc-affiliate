# Local setup on Windows (PowerShell)

## 1. Install the tools

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2 or newer (8.3 recommended) | Easiest route: [Laravel Herd for Windows](https://herd.laravel.com/windows), which includes PHP and Composer. Or install PHP manually and enable `pdo_mysql`, `mbstring`, `intl`, `openssl`, `fileinfo`, `zip` in `php.ini`. |
| Composer | 2.x | Included with Herd |
| Node.js | 22 LTS | https://nodejs.org |
| MySQL | 8.0 | MySQL Installer for Windows, or Docker Desktop with `docker compose up -d` from this repo |
| Git | any recent | https://git-scm.com |

Check them:

```powershell
php -v
composer --version
node -v
mysql --version   # or: docker compose ps
```

## 2. Create the database

With the MySQL CLI (as an admin user):

```sql
CREATE DATABASE nfc_affiliate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nfc_affiliate'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL PRIVILEGES ON nfc_affiliate.* TO 'nfc_affiliate'@'localhost';
```

Or with Docker: `docker compose up -d` starts MySQL and Mailpit using the `DB_*` values from your `.env`.

## 3. Install and configure

```powershell
git clone <repository-url> nfc-affiliate
cd nfc-affiliate
composer install
npm install
Copy-Item .env.example .env
php artisan key:generate
notepad .env    # set DB_DATABASE, DB_USERNAME, DB_PASSWORD
```

Do not overwrite an existing `.env` that holds real settings.

To see emails in a browser with Mailpit, set `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1` and `MAIL_PORT=1025`, then open http://localhost:8025.

## 4. Create tables and the first administrator

```powershell
php artisan migrate --seed
php artisan affiliate:create-admin
```

The seeder creates roles and permissions only. It never creates users or financial data. Optional demo users for local testing:

```powershell
php artisan db:seed --class=DevelopmentSeeder   # refuses to run when APP_ENV=production
```

## 5. Run it

Use three PowerShell windows:

```powershell
php artisan serve        # http://localhost:8000
npm run dev              # Vite with hot reload (or `npm run build` once)
php artisan queue:work   # sends verification and reset emails
```

## 6. Run the checks

```powershell
php artisan test
npm run typecheck
npm run lint
npm run build
```

To run the tests against MySQL instead of in-memory SQLite, create an empty `nfc_affiliate_test` database and run:

```powershell
$env:DB_CONNECTION="mysql"; $env:DB_DATABASE="nfc_affiliate_test"; php artisan test
```

## Troubleshooting

- **`could not find driver`**: enable `extension=pdo_mysql` in `php.ini`.
- **419 or CSRF errors in the browser**: open the app on the same host and port listed in `SANCTUM_STATEFUL_DOMAINS` (for example `localhost:8000`, not `127.0.0.1:8000`, unless you add it).
- **No verification email**: check that `php artisan queue:work` is running, and look in `storage/logs/` when `MAIL_MAILER=log`.
