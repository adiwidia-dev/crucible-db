#!/bin/sh
set -eu

: "${APP_KEY:?APP_KEY must be configured for production}"

mkdir -p \
    /app/storage/database \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f "${DB_DATABASE:-/app/storage/database/crucible.sqlite}" ]; then
    touch "${DB_DATABASE:-/app/storage/database/crucible.sqlite}"
fi

database_attempt=1
database_max_attempts="${DATABASE_STARTUP_ATTEMPTS:-30}"

until php artisan migrate --force --no-interaction; do
    if [ "$database_attempt" -ge "$database_max_attempts" ]; then
        echo "Application database did not become ready after ${database_max_attempts} attempts." >&2
        exit 1
    fi

    echo "Application database is not ready (attempt ${database_attempt}/${database_max_attempts}); retrying in 2 seconds." >&2
    database_attempt=$((database_attempt + 1))
    sleep 2
done

exec "$@"
