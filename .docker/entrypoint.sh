#!/bin/sh
set -eu

mkdir -p \
    /app/storage/database \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs

if [ ! -f /app/storage/database/crucible.sqlite ]; then
    touch /app/storage/database/crucible.sqlite
fi

if [ ! -f /app/vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --ignore-platform-req=ext-pcntl
fi

exec "$@"
