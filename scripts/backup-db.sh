#!/usr/bin/env bash
# Consistent, compressed, optionally encrypted MySQL backup.
#
#   DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD   connection (from the environment)
#   BACKUP_DIR            where to write (default ./storage/backups, keep it outside the web root)
#   BACKUP_PASSPHRASE     if set, the dump is encrypted with AES-256 (openssl)
#   BACKUP_KEEP_DAYS      delete backups older than this (default 30)
#
# The password is passed in a temporary options file, never on the command line.
# Payout details are encrypted with APP_KEY: back up APP_KEY separately and
# securely, or those fields cannot be read after a restore.
# See docs/backup-and-restore.md.
set -euo pipefail

: "${DB_DATABASE:?set DB_DATABASE}" "${DB_USERNAME:?set DB_USERNAME}"
BACKUP_DIR="${BACKUP_DIR:-$(dirname "$0")/../storage/backups}"
KEEP="${BACKUP_KEEP_DAYS:-30}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

opts=$(mktemp)
trap 'rm -f "$opts"' EXIT
chmod 600 "$opts"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\n' \
  "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" "$DB_USERNAME" "${DB_PASSWORD:-}" > "$opts"

stamp=$(date -u +%Y%m%dT%H%M%SZ)
out="$BACKUP_DIR/${DB_DATABASE}-${stamp}.sql.gz"

# --single-transaction: a consistent snapshot of InnoDB tables without locking the shop.
dump() {
  mysqldump --defaults-extra-file="$opts" --single-transaction --quick --routines --triggers \
    --hex-blob --set-gtid-purged=OFF --no-tablespaces "$DB_DATABASE" | gzip -9
}

umask 077
if [[ -n "${BACKUP_PASSPHRASE:-}" ]]; then
  out="$out.enc"
  dump | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE -out "$out"
else
  dump > "$out"
fi

sha256sum "$out" > "$out.sha256"
find "$BACKUP_DIR" -name "${DB_DATABASE}-*.sql.gz*" -mtime +"$KEEP" -delete
echo "$out"
