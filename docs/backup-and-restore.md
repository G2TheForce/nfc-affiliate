# Backup and restore

## What to back up

| Item | Why | How |
|---|---|---|
| MySQL database | Orders, commissions, the ledger, payouts, audit log | `scripts/backup-db.sh` (below) |
| `APP_KEY` | Payout bank and wallet details are encrypted with it. **A database restored without the same key cannot show those details**; affiliates would have to re-enter them. | Store it in a password manager or secrets vault, separately from the database backups. Never in the repository. |
| `.env` | Every other setting and credential | Same place as `APP_KEY` |
| `storage/app/private/marketing` and `storage/app/private/products` | Marketing materials and product photos uploaded by staff. Without them the records remain, but downloads and photos fail until the files are uploaded again. | Copy both folders off the server with the database backups, for example `rsync -a storage/app/private/ <backup-host>:affiliate/files/`. With Docker they are in the `app-storage` volume. |

## Making a backup

```bash
export DB_HOST=127.0.0.1 DB_DATABASE=affiliate DB_USERNAME=backup_user DB_PASSWORD=...
export BACKUP_DIR=/var/backups/affiliate BACKUP_PASSPHRASE=...   # passphrase from your vault
scripts/backup-db.sh
```

The script:

- takes a consistent snapshot with `mysqldump --single-transaction`, so the shop keeps running;
- compresses it and encrypts it with AES-256 when `BACKUP_PASSPHRASE` is set;
- writes a SHA-256 checksum next to the file;
- deletes backups older than `BACKUP_KEEP_DAYS` (default 30).

The database password goes in a temporary options file readable only by the script's user, never on the command line. The backup user needs only `SELECT`, `SHOW VIEW`, `TRIGGER` and `LOCK TABLES` on the database.

Suggested schedule (cron, as the deploy user):

```
15 2 * * * cd /var/www/affiliate && . ./backup.env && scripts/backup-db.sh >> storage/logs/backup.log 2>&1
```

Copy each backup off the server: a different machine, or object storage with versioning. A backup on the same disk does not survive losing that disk.

## Restoring

Restore into a **new, empty** database first; the script refuses to overwrite a database that has tables unless `RESTORE_FORCE=1`.

```bash
export DB_DATABASE=affiliate_restore DB_USERNAME=... DB_PASSWORD=... BACKUP_PASSPHRASE=...
scripts/restore-db.sh /var/backups/affiliate/affiliate-20261012T021500Z.sql.gz.enc
DB_DATABASE=affiliate_restore php artisan affiliate:reconcile-commissions   # must say "clean"
```

The checksum is verified before anything is written. When the restored copy checks out:

1. Put the shop in maintenance mode with `php artisan down`.
2. Point `DB_DATABASE` at the restored database.
3. Run `php artisan config:cache`.
4. Bring the shop back with `php artisan up`.

Online payments that customers completed after the backup was taken are not lost. `payments:recheck` asks eSewa and Khalti again only for attempts that exist in the restored database, so compare each provider's merchant portal for that window by hand.

## Restore drill (2026-10-12, actual results)

| Check | Result |
|---|---|
| Data | Load-test database: 1,000 affiliates, 500k clicks, 50k orders, 65,653 commissions, 200,594 ledger entries, plus one encrypted payout method |
| Backup | 13.6 s, 31.7 MB encrypted |
| Restore into an empty database | succeeded |
| `CHECKSUM TABLE` on all 48 tables | identical to the source |
| `affiliate:reconcile-commissions` on the restored copy | clean |
| Payout details with the same `APP_KEY` | readable |
| Payout details with a different `APP_KEY` | `DecryptException`, which confirms why the key must be backed up |
| Second restore into the now-full database | refused |
| Wrong passphrase | refused; no tables written |
| Tampered backup file | refused (checksum mismatch) |

Repeat the drill at least every quarter, and after any schema change.
