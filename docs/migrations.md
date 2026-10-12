# Database migrations

## Running them

```bash
php artisan migrate:status                         # what has run, what is pending
php artisan migrate --force                        # production: runs pending migrations
php artisan migrate --force --seed                 # first install only: also creates roles and permissions
```

Run migrations with the database user that may change the schema, not the app user (see [deployment.md](deployment.md#3-database)). Take a backup first (`scripts/backup-db.sh`). `--seed` runs `DatabaseSeeder`, which only creates roles and permissions. It never creates users or sample data; `DevelopmentSeeder` refuses to run in production.

The app must be in maintenance mode (`php artisan down`) while migrations run on an existing install.

## What each migration does

None of the 1.0.0, 1.1.0 or 1.2.0 migrations changes or deletes existing data. Each one creates tables, adds columns, or adds indexes. The one data step, in the commission migration, fills a new `public_id` column on existing order lines. Every migration has a `down()`. Up, down, up was tested on MySQL 8 for each 1.0.0 phase; the results for the 1.1.0 and 1.2.0 migrations are in the [Phase 10 report](phase-10-report.md) and the [Phase 11 report](phase-11-report.md).

| Migration | Creates or changes |
|---|---|
| `0001_01_01_000000_create_users_table` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table` | `cache`, `cache_locks` (also used for scheduler locks and rate limits) |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `2026_10_09_153346_create_personal_access_tokens_table` | `personal_access_tokens` (Sanctum API tokens) |
| `2026_10_09_153347_create_permission_tables` | roles, permissions and their links (spatie/laravel-permission) |
| `2026_10_09_160000_create_audit_logs_table` | `audit_logs` (append-only) |
| `2026_10_10_071408_create_notifications_table` | `notifications` (in-app notices) |
| `2026_10_10_100000_create_affiliate_tables` | affiliate profiles, status history, terms versions and acceptances, staff notes |
| `2026_10_11_100000_create_store_tables` | product categories, products, coupons, orders, order lines, coupon redemptions |
| `2026_10_11_100100_create_referral_tables` | `affiliate_links`, `referral_clicks`, `order_attributions`, `referral_events` |
| `2026_10_12_100000_create_commission_tables` | commission plans and rules, payments, refunds, commissions, adjustments, the commission ledger, reconciliation runs; adds paid and refunded columns to orders and lines, a plan to affiliate profiles, and `public_id` to order lines (filled for existing rows) |
| `2026_10_13_100000_create_payout_tables` | payout methods (encrypted), payouts, payout items; adds payout links to commissions and the ledger |
| `2026_10_14_100000_create_payment_integration_tables` | `payment_attempts`, `webhook_events` (stored provider redirects), `order_disputes`; adds the guest access token hash and delivery columns to orders, and provider references to payments |
| `2026_10_15_100000_add_reporting_indexes` | five indexes on orders, clicks, refunds and payouts for reports |
| `2026_10_16_100000_create_fraud_flags_table` (1.1.0) | `fraud_flags` (signals for staff review) |
| `2026_10_17_100000_create_campaign_tables` (1.1.0) | `campaigns`, `campaign_affiliate`, `campaign_product`; adds a nullable `campaign_id` to `affiliate_links`, `coupons`, `order_attributions` and `commission_rules` (existing rows stay null) |
| `2026_10_18_100000_create_marketing_assets_table` (1.1.0) | `marketing_assets` (the files themselves go in `storage/app/private/marketing`) |
| `2026_10_19_100000_create_system_settings_table` (1.2.0) | `system_settings` (programme settings changed on the Settings screen; an empty table means every `.env` value applies) |
| `2026_10_20_100000_add_image_to_products` (1.2.0) | adds a nullable `image_path` to `products` (the files go in `storage/app/private/products`) |

### Upgrading from 1.0.0 to 1.1.0

The three 1.1.0 migrations only create tables and add nullable columns. Existing orders, commissions and rules are not touched, and no data step runs. Back up, put the app in maintenance mode, run `php artisan migrate --force`, then `php artisan up`. Also:

- add the new `.env` settings from `.env.example` if you want values other than the defaults (`AFFILIATE_MARKETING_MAX_UPLOAD_KB` and the `AFFILIATE_FRAUD_*` thresholds);
- raise the PHP and nginx upload limits for 20 MB marketing files ([deployment.md](deployment.md));
- add `storage/app/private/marketing` to the file backups ([backup-and-restore.md](backup-and-restore.md)).

Rolling back `2026_10_17_100000_create_campaign_tables` drops the `campaign_id` columns, so orders lose the record of which campaign they came through.

### Upgrading from 1.1.0 to 1.2.0

The two 1.2.0 migrations create an empty `system_settings` table and add a nullable `image_path` column to `products`. No data step runs, and every setting keeps its `.env` value until someone saves a change on the Settings screen. Back up, put the app in maintenance mode, run `php artisan migrate --force`, then `php artisan up`. Also:

- add `storage/app/private/products` to the file backups ([backup-and-restore.md](backup-and-restore.md));
- run `php artisan queue:restart` as on every deploy.

Rolling back `2026_10_19_100000_create_system_settings_table` drops every saved setting, so the `.env` values apply again. Rolling back `2026_10_20_100000_add_image_to_products` drops the photo links; the files stay on disk but are no longer served.

The full table reference is [database-schema.md](database-schema.md).

## Rolling back

- `php artisan migrate:rollback --step=1` undoes the most recent migration.
- Rolling back a migration that **created** tables drops those tables and their data. Rolling back one that **added** columns drops those columns. On a live system, prefer restoring the pre-upgrade backup into a new database ([backup-and-restore.md](backup-and-restore.md)), or leave the schema in place when the older code can run on it. Every 1.0.0, 1.1.0 and 1.2.0 migration is additive, so older code can.
- Never run `migrate:fresh`, `migrate:reset` or `db:wipe` on production: they delete everything.

## Writing new migrations

- Make them additive where possible: add a column as nullable or with a default, backfill in batches, and tighten it in a later release.
- Keep money in integer minor units (`bigInteger`, paisa) with a currency code; see [ADR 0001](adr/0001-money-as-integer-minor-units.md).
- Never update or delete rows in `audit_logs`, `commission_ledger_entries`, `order_payments`, `refunds` or `payout_items`; they are append-only or immutable by design.
- Test up, down and up again on MySQL 8 before merging, and run `affiliate:reconcile-commissions` afterwards.
