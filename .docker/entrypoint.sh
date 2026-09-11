#!/bin/sh
set -eu

mkdir -p \
    /app/storage/database \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    sqlite_database="${DB_DATABASE:-/app/database/database.sqlite}"
    mkdir -p "$(dirname "$sqlite_database")"

    if [ ! -f "$sqlite_database" ]; then
        touch "$sqlite_database"
    fi
fi

if [ ! -f /app/vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --ignore-platform-req=ext-pcntl
fi

exec "$@"
