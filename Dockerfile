FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        nodejs \
        npm \
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
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
