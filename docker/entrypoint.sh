#!/bin/sh
# Builds Laravel's caches from the runtime environment, then runs the command.
# Migrations are not run here: run them once per release with
#   docker compose -f compose.production.yml run --rm app php artisan migrate --force
set -e
if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one once (php artisan key:generate --show), store it safely and put it in .env." >&2
  exit 1
fi
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null
php artisan event:cache >/dev/null
exec "$@"
