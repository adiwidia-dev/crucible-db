#!/bin/sh
set -eu

: "${APP_KEY:?APP_KEY must be configured for production}"

mkdir -p \
    /app/storage/database \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs

if [ ! -f /app/storage/database/crucible.sqlite ]; then
    touch /app/storage/database/crucible.sqlite
fi

php artisan package:discover --ansi --no-interaction
php artisan migrate --force --no-interaction

exec "$@"
