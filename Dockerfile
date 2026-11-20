FROM node:26-bookworm-slim AS node

FROM dunglas/frankenphp:1-php8.5.13@sha256:17ff6554ba6de527d17aa5ec37e2a3bc3b182d7dbea9362f9a02eb5b4a1c4b95 AS base

WORKDIR /app

COPY --from=node /usr/local /usr/local

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        default-mysql-client \
        postgresql-client \
    && install-php-extensions \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        redis \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY .docker/Caddyfile /etc/caddy/Caddyfile
COPY .docker/entrypoint.sh /usr/local/bin/crucible-entrypoint

RUN chmod +x /usr/local/bin/crucible-entrypoint

ENTRYPOINT ["crucible-entrypoint"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=2", "--max-requests=500", "--caddyfile=/etc/caddy/Caddyfile"]
