#!/usr/bin/env bash
# Restores a backup made by backup-db.sh into DB_DATABASE.
#
#   scripts/restore-db.sh <backup file>
#
# Refuses to overwrite a database that already has tables unless
# RESTORE_FORCE=1. Restore into a new, empty database first, check it with
# `php artisan affiliate:reconcile-commissions`, then switch the app over.
# See docs/backup-and-restore.md.
set -euo pipefail

file="${1:?usage: restore-db.sh <backup file>}"
: "${DB_DATABASE:?set DB_DATABASE}" "${DB_USERNAME:?set DB_USERNAME}"

if [[ -f "$file.sha256" ]]; then
  sha256sum --check --status "$file.sha256" || { echo "Checksum mismatch: $file" >&2; exit 1; }
fi

opts=$(mktemp)
trap 'rm -f "$opts"' EXIT
chmod 600 "$opts"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\n' \
  "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" "$DB_USERNAME" "${DB_PASSWORD:-}" > "$opts"

tables=$(mysql --defaults-extra-file="$opts" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_DATABASE'")
if [[ "$tables" != "0" && "${RESTORE_FORCE:-0}" != "1" ]]; then
  echo "Refusing: $DB_DATABASE already has $tables tables. Restore into an empty database, or set RESTORE_FORCE=1." >&2
  exit 1
fi

if [[ "$file" == *.enc ]]; then
  : "${BACKUP_PASSPHRASE:?set BACKUP_PASSPHRASE to decrypt}"
  openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in "$file" | gunzip | mysql --defaults-extra-file="$opts" "$DB_DATABASE"
else
  gunzip -c "$file" | mysql --defaults-extra-file="$opts" "$DB_DATABASE"
fi
echo "Restored $file into $DB_DATABASE"
