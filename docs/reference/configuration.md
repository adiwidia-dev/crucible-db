# Configuration reference

Crucible DB reads local development values from `.env`. Production commands should use a private `.env.production` copied from `.env.production.example`:

```bash
cp .env.production.example .env.production
docker compose --env-file .env.production -f compose.production.yaml up -d
```

The tables below document the operator-facing environment variables supported by the supplied environment templates and Compose files. Laravel supports additional framework-level variables, but they are outside the supported deployment topology unless they are listed here.

`Empty` means that no value is configured. `Required` means that Crucible DB or Compose refuses to start the affected service without a value. Values shown as `Compose: ...` override the corresponding environment-file value in the supplied stack.

!!! warning "Keep environment files private"
    Never commit `.env` or `.env.production`. They can contain application encryption keys, control-database credentials, SMTP credentials, and the native-proxy control secret.

## Required production values

Set these values before the first production start.

| Variable | Default | Explanation |
| --- | --- | --- |
| `APP_KEY` | Required | Laravel encryption key used for encrypted settings, credentials, sessions, and provider secrets. Generate a unique key for every installation. |
| `APP_URL` | `https://crucible.example.com` placeholder | Public HTTPS origin used for links, redirects, SSO callbacks, and native-client discovery. Replace the placeholder with the real origin. |
| `CRUCIBLE_INITIAL_SETUP_TOKEN` | Required | One-time setup secret used before the first administrator exists. Use at least 32 random characters and rotate or remove it after setup. |
| `NATIVE_PROXY_CONTROL_SECRET` | Required | Shared secret authenticating and encrypting proxy-to-Laravel control traffic. Use at least 32 random characters and never reuse `APP_KEY`. |
| `CRUCIBLE_NATIVE_IMAGE` | Required | Immutable native-proxy image matching the application release. There is deliberately no fallback image. |
| `CRUCIBLE_CONTROL_DATABASE_PASSWORD` | Required only with a control-database profile | Password provisioned into the optional Compose-managed PostgreSQL or MySQL control database. |

Generate the three local secrets with:

```bash
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
printf 'CRUCIBLE_INITIAL_SETUP_TOKEN=%s\n' "$(openssl rand -hex 32)"
printf 'NATIVE_PROXY_CONTROL_SECRET=%s\n' "$(openssl rand -hex 32)"
```

Copy the generated values into `.env.production`; do not use the command output directly as a checked-in file.

## Application and HTTP runtime

| Variable | Development default | Production default | Explanation |
| --- | --- | --- | --- |
| `APP_NAME` | `Crucible DB` | `Crucible DB` | Default product name before an administrator saves an application name in workspace settings. |
| `APP_ENV` | `local` | `production` (forced by Compose) | Laravel runtime environment. Production Compose always forces `production`. |
| `APP_KEY` | Empty | Required | Encryption key. Run `php artisan key:generate` for native development or set a generated production value. |
| `APP_DEBUG` | `true` | `false` (forced by Compose) | Detailed exception output. It must remain disabled in production. |
| `APP_URL` | `http://localhost:8000` | Required HTTPS origin | Canonical public origin for generated links, callbacks, and native-client discovery. |
| `APP_LOCALE` | `en` | `en` | Default application language. |
| `APP_FALLBACK_LOCALE` | `en` | `en` | Language used when a translation is unavailable. |
| `APP_FAKER_LOCALE` | `en_US` | `en_US` | Locale used by development and test data generators. |
| `APP_MAINTENANCE_DRIVER` | `file` | `file` | Laravel maintenance-mode coordination driver. Crucible's database-migration fence is separate from this setting. |
| `APP_MAINTENANCE_STORE` | `database` | `database` | Cache store used only when `APP_MAINTENANCE_DRIVER=cache`. |
| `APP_PREVIOUS_KEYS` | Empty | Empty | Comma-separated former application keys used temporarily while rotating `APP_KEY`. Remove them after encrypted data has been re-encrypted. |
| `BCRYPT_ROUNDS` | `12` | `12` | Work factor for bcrypt password hashes. Higher values increase login and password-change CPU cost. |
| `AUTH_INVITATION_EXPIRATION_DAYS` | `7` | `7` | Number of days before an unused account invitation expires. |
| `PASSKEYS_USER_HANDLE_SECRET` | `APP_KEY` | `APP_KEY` | Secret used to derive WebAuthn user handles. Override only when deliberately separating it from `APP_KEY`, then preserve it across deployments. |
| `CRUCIBLE_INITIAL_SETUP_TOKEN` | `crucible-local-initial-setup-token` | Required | Secret required by the first-run web setup. The development fallback is safe only while the app remains loopback-only. |
| `CRUCIBLE_BIND_ADDRESS` | `127.0.0.1` | `127.0.0.1` | Host interface publishing the bundled HTTP origin. Keep it loopback-only behind Nginx, Cloudflare Tunnel, or another TLS terminator. |
| `CRUCIBLE_HTTP_PORT` | `8000` | `8000` | Host port publishing the bundled HTTP origin. |
| `TRUSTED_PROXIES` | `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1/8,::1` | Same as development | Comma-separated proxy IPs or CIDRs allowed to supply forwarded client, host, port, and HTTPS scheme headers. Narrow this when the proxy network is known. |
| `OCTANE_WORKERS` | `2` in development Compose | `2` | FrankenPHP request-worker count. Increase only after measuring memory and load. |
| `OCTANE_MAX_REQUESTS` | `500` in development Compose | `500` | Requests served before an Octane worker is recycled. |
| `VITE_APP_NAME` | `${APP_NAME}` | Build-time value | Application name exposed to Vite during frontend development or asset builds. |

## Application database

In `managed` mode, the web setup and **Manage → Administration → Application → Database** own the active control-database selection. The encrypted managed selection overrides `DB_*` after it exists. In `environment` mode, `DB_*` remains authoritative and the migration UI is read-only.

| Variable | Default | Explanation |
| --- | --- | --- |
| `CRUCIBLE_DATABASE_CONFIG_MODE` | `managed` | `managed` stores an encrypted SQLite, PostgreSQL, or MySQL selection; `environment` always uses `DB_*`. |
| `CRUCIBLE_DATABASE_CONFIG_FILE` | `storage/app/crucible/application-database.enc`; production template uses `/app/storage/app/crucible/application-database.enc` | Persistent encrypted active-database selection. Keep it in the `crucible_storage` volume and outside every migratable database. |
| `CRUCIBLE_DATABASE_MIGRATION_DIRECTORY` | `storage/app/crucible/application-database-migrations`; production uses the absolute `/app/storage/...` path | Directory holding encrypted migration plans, history, and progress records. |
| `CRUCIBLE_DATABASE_MIGRATION_FENCE_FILE` | `storage/app/crucible/application-database-migration.fence`; production uses the absolute `/app/storage/...` path | Filesystem maintenance fence used to block mutations during copy, cutover, and rollback. |
| `DATABASE_STARTUP_ATTEMPTS` | `30` | Number of two-second migration/startup attempts before the production app container exits. |
| `DB_CONNECTION` | `sqlite` | Fallback or environment-owned control driver. Supported values are `sqlite`, `pgsql`, and `mysql`. |
| `DB_URL` | Empty | Optional database URL. When provided, it can replace the separate host, port, database, username, and password values. |
| `DB_DATABASE` | `database/database.sqlite` when omitted; production template uses `/app/storage/database/crucible.sqlite` | SQLite file path or network database name. Network databases must set their database name explicitly; use an absolute in-container path for production SQLite. |
| `DB_HOST` | `127.0.0.1` | PostgreSQL or MySQL host. Use the Compose service name or a private external endpoint from inside the app container. |
| `DB_PORT` | Driver default (`5432` PostgreSQL, `3306` MySQL) | PostgreSQL or MySQL server port. |
| `DB_USERNAME` | `root` when omitted; production template is empty | Network control-database account. Use a dedicated least-privilege account. |
| `DB_PASSWORD` | Empty | Network control-database password. |
| `DB_SOCKET` | Empty | Optional MySQL Unix socket path. Not used by the supplied Compose topology. |
| `DB_CHARSET` | Driver default | Optional database charset override. Defaults to `utf8` for PostgreSQL and `utf8mb4` for MySQL. |
| `DB_COLLATION` | Driver default | Optional MySQL collation override; defaults to `utf8mb4_unicode_ci`. |
| `DB_FOREIGN_KEYS` | `true` | Enables SQLite foreign-key enforcement. |
| `DB_BUSY_TIMEOUT` | `5000` | SQLite lock wait in milliseconds. |
| `DB_JOURNAL_MODE` | `WAL` | SQLite journal mode. WAL improves concurrent reader/writer behavior. |
| `DB_SYNCHRONOUS` | `FULL` | SQLite durability level. Keep `FULL` unless the durability tradeoff is understood. |
| `DB_TRANSACTION_MODE` | `IMMEDIATE` | SQLite transaction mode used to acquire write intent early and reduce late lock failures. |
| `DB_SSLMODE` | `prefer` | PostgreSQL TLS mode. Production operators should choose the verification mode required by their database provider. |
| `MYSQL_ATTR_SSL_CA` | Empty | Optional in-container CA certificate path for a TLS-protected MySQL control database. |

### Optional Compose-managed control databases

These values are used only when starting the production `control-postgres` or `control-mysql` profile.

| Variable | Default | Explanation |
| --- | --- | --- |
| `CRUCIBLE_CONTROL_DATABASE_NAME` | `crucible` | Database provisioned by the selected profile. |
| `CRUCIBLE_CONTROL_DATABASE_USERNAME` | `crucible` | Non-root database account provisioned by the selected profile. |
| `CRUCIBLE_CONTROL_DATABASE_PASSWORD` | Required for a profile | Password assigned to the provisioned account. |

## Queues, cache, sessions, and Redis

The supplied Compose topology uses Redis for queues, cache, sessions, Horizon metadata, and native-proxy revocation. Compose sets the service host and the Redis-backed Laravel drivers.

| Variable | Development default | Production default | Explanation |
| --- | --- | --- | --- |
| `QUEUE_CONNECTION` | `redis` | `redis` (forced by Compose) | Laravel queue backend used by Horizon. |
| `CACHE_STORE` | `redis` | `redis` (forced by Compose) | Laravel cache backend, including distributed application state. |
| `SESSION_DRIVER` | `redis` | `redis` (forced by Compose) | Browser-session storage backend. |
| `SESSION_LIFETIME` | `120` | `120` | Idle session lifetime in minutes. |
| `SESSION_ENCRYPT` | `false` | `true` | Encrypts serialized session payloads before storage. Production should remain enabled. |
| `SESSION_SECURE_COOKIE` | Unset for local HTTP | `true` (forced by Compose) | Sends the session cookie only over HTTPS. Keep this enabled when the public `APP_URL` is HTTPS. |
| `SESSION_PATH` | `/` | `/` | Cookie path. |
| `SESSION_DOMAIN` | `null` | `null` | Cookie domain. Leave unset for the current host; configure carefully when sharing cookies across subdomains. |
| `REDIS_CLIENT` | `phpredis` | `phpredis` | PHP Redis client implementation. |
| `REDIS_HOST` | `redis` | `redis` (forced by Compose) | Internal Redis service hostname. |
| `REDIS_PASSWORD` | `null` | `null` | Redis password. The supplied private Compose network does not configure one. |
| `REDIS_PORT` | `6379` | `6379` | Redis server port. |
| `BROADCAST_CONNECTION` | `log` | `log` | Laravel broadcast driver. The supplied topology does not include a realtime broadcast server. |

Do not override the Compose-managed Redis, queue, cache, or session values unless you deliberately redesign and test the runtime topology. Every Laravel runtime must share these services.

## Logging, mail, and storage

| Variable | Development default | Production default | Explanation |
| --- | --- | --- | --- |
| `LOG_CHANNEL` | `stack` | `stderr` | Laravel logging channel. Production writes to container standard error. |
| `LOG_STACK` | `single` | `single` | Comma-separated channels included when `LOG_CHANNEL=stack`. |
| `LOG_DEPRECATIONS_CHANNEL` | `null` | `null` | Optional channel receiving deprecation notices. |
| `LOG_LEVEL` | `debug` | `warning` | Minimum application log severity. Avoid debug logging in production because query context may be sensitive. |
| `MAIL_MAILER` | `smtp` | `smtp` | Mail transport used for invitations, password resets, and operational notifications. |
| `MAIL_SCHEME` | `null` | `null` | Explicit SMTP URL scheme when required by the provider. |
| `MAIL_HOST` | `mailpit` | Empty | SMTP server hostname. It is required when email delivery is enabled. The default development stack does not include Mailpit, so supply a reachable host if testing email. |
| `MAIL_PORT` | `1025` | `587` | SMTP server port. |
| `MAIL_USERNAME` | `null` | Empty | SMTP username. |
| `MAIL_PASSWORD` | `null` | Empty | SMTP password. |
| `MAIL_ENCRYPTION` | `null` | `tls` | SMTP transport encryption. |
| `MAIL_FROM_ADDRESS` | `hello@crucible-db.local` | Empty | Sender address. It is required when email delivery is enabled. |
| `MAIL_FROM_NAME` | `${APP_NAME}` | `${APP_NAME}` | Sender display name. |
| `FILESYSTEM_DISK` | `local` | `local` | Laravel default filesystem disk. The supplied production volume persists the local disk. |
| `MEMCACHED_HOST` | `127.0.0.1` | `127.0.0.1` | Memcached endpoint when replacing the supported Redis cache topology. No Memcached service is supplied. |
| `AWS_ACCESS_KEY_ID` | Empty | Empty | AWS-compatible object-storage or SQS access key. |
| `AWS_SECRET_ACCESS_KEY` | Empty | Empty | AWS-compatible object-storage or SQS secret key. |
| `AWS_DEFAULT_REGION` | `us-east-1` | `us-east-1` | AWS region. |
| `AWS_BUCKET` | Empty | Empty | S3-compatible bucket name. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | `false` | Enables path-style S3-compatible endpoints. |

## Native proxy

The application and native-proxy containers consume different subsets of these values. Production Compose intentionally passes only the proxy's public-origin, control, Redis, identity, limit, and timeout settings into the proxy container; do not attach the complete application environment file to that service.

| Variable | Default | Explanation |
| --- | --- | --- |
| `NATIVE_PROXY_ENABLED` | `false`; supplied Compose stacks force `true` | Enables Native Client Query Access in Laravel. Keep it enabled only when the matching native-proxy service is present. |
| `NATIVE_PROXY_CONTROL_SECRET` | Required by Compose | Shared authentication and AES-256-GCM response-encryption secret. It must contain at least 32 non-default characters. |
| `NATIVE_PROXY_CONTROL_ENCRYPTED_RESPONSES_REQUIRED` | `true`; forced by Compose | Rejects control responses that are not authenticated and encrypted. Keep application and proxy images on the same release. |
| `NATIVE_PROXY_ALLOWED_IDS` | Empty; Compose uses `NATIVE_PROXY_ID` | Comma-separated proxy instance IDs allowed to call Laravel's private control API. |
| `NATIVE_PROXY_ALLOWED_IPS` | Empty | Optional comma-separated direct peer IP allowlist for control calls. Forwarded client headers are not trusted for this check. |
| `NATIVE_PROXY_ID` | `crucible-dev` development; `crucible-production` production | Stable operator-visible identifier for a proxy instance. It must also appear in Laravel's allowed proxy IDs. |
| `NATIVE_PROXY_CONTROL_URL` | `http://app:8001` in Compose | Private Laravel control endpoint used only by the proxy container. Never route this endpoint publicly. |
| `NATIVE_PROXY_REDIS_URL` | `redis://redis:6379/0` in Compose | Redis endpoint used by the proxy for immediate lease-revocation signals. |
| `NATIVE_PROXY_REVOCATION_CHANNEL` | `native-proxy:lease-revoked` | Redis Pub/Sub channel shared by Laravel and every proxy replica. |
| `NATIVE_PROXY_MAX_CONNECTIONS` | `100` | Distributed global connection ceiling reserved by Laravel before upstream credentials are released. Use the same value on every proxy replica. |
| `NATIVE_PROXY_MAX_CONNECTIONS_PER_LEASE` | `3` | Per-lease connection ceiling enforced by the proxy. |
| `NATIVE_PROXY_MAX_CONNECTIONS_PER_USER` | `10` | Distributed per-user connection ceiling. Use the same value on every proxy replica. |
| `NATIVE_PROXY_MAX_PENDING_DEVICE_AUTHORIZATIONS_PER_LEASE` | `3` | Maximum pending browser authorization challenges for one lease. |
| `NATIVE_PROXY_CONNECTION_STALE_SECONDS` | `60` | Marks a durable active-connection record failed when its activity heartbeat becomes stale. |
| `NATIVE_PROXY_STATEMENT_STALE_SECONDS` | `900` | Marks a running native statement failed when no completion record arrives within this many seconds. |
| `NATIVE_PROXY_IDLE_TIMEOUT` | `15m` | Maximum inactivity on a PostgreSQL or MySQL client socket before the proxy closes it. Uses Go duration syntax. |
| `NATIVE_PROXY_DRAIN_TIMEOUT` | `30s` | Maximum graceful shutdown wait for active sessions. Keep the container stop grace period longer than this value. |
| `NATIVE_PROXY_READINESS_INTERVAL` | `5s` | Interval between signed Laravel control-plane and Redis dependency checks. Uses Go duration syntax. |
| `NATIVE_PROXY_HEALTH_URL` | `http://native-proxy:8081/readyz` in Compose | Internal readiness endpoint polled by Laravel's scheduled health check. |
| `NATIVE_PROXY_EXPECTED_VERSION` | Empty | Optional expected proxy release. A mismatch creates an operator alert. |
| `NATIVE_PROXY_CLI_DOWNLOAD_URL` | `https://github.com/adiwidia-dev/crucible-db/releases/latest` | Release or mirror page opened from the Native Client Access workspace. Forks may point this to their artifact distribution page. |
| `NATIVE_PROXY_POSTGRES_LISTEN` | `:5432` | Internal PostgreSQL listener address inside the native-proxy container. Compose does not publish it to the host. |
| `NATIVE_PROXY_MYSQL_LISTEN` | `:3306` | Internal MySQL listener address inside the native-proxy container. Compose does not publish it to the host. |
| `NATIVE_PROXY_GATEWAY_LISTEN` | `:8081` | Internal HTTP tunnel and readiness listener. Despite the historical variable name, this is a listener inside the native-proxy process, not a standalone gateway service. The app's embedded Caddy route forwards only the fixed public native-client paths to it. |

## Compose images and files

These variables are evaluated by Docker Compose. The recommended production commands pass `.env.production` with `--env-file`, so values placed there participate in interpolation as well as being loaded into the app service. An `env_file:` service entry by itself does not participate in Compose interpolation.

| Variable | Default | Explanation |
| --- | --- | --- |
| `CRUCIBLE_IMAGE` | `hephaestus/crucible-db:0.1.0` | Application image used by production Compose. Pin an immutable release or digest. |
| `CRUCIBLE_NATIVE_IMAGE` | Required | Matching native-proxy image. Pin the same release as `CRUCIBLE_IMAGE`. |
| `CRUCIBLE_ENV_FILE` | `.env.production` | Environment file loaded into the production app container. |
| `NATIVE_POSTGRES_IMAGE` | `postgres:17-alpine` | Development target PostgreSQL image. |
| `NATIVE_MYSQL_IMAGE` | `mysql:8.4` | Development target MySQL image. |
| `CONTROL_POSTGRES_IMAGE` | `postgres:17-alpine` | PostgreSQL image used by the development application-database migration test profile. |
| `CONTROL_MYSQL_IMAGE` | `mysql:8.4` | MySQL image used by the development application-database migration test profile. |

## Security checklist

- Generate unique `APP_KEY`, `CRUCIBLE_INITIAL_SETUP_TOKEN`, and `NATIVE_PROXY_CONTROL_SECRET` values.
- Restrict filesystem permissions for `.env.production` and all volume backups.
- Keep `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and the external `APP_URL` on HTTPS in production.
- Terminate TLS with an administrator-managed reverse proxy or tunnel in front of the loopback-only HTTP origin.
- Do not publish native PostgreSQL, MySQL, Redis, metrics, readiness, or private control ports.
- Keep every Laravel runtime on the same application-database selection. Managed cutover requires a coordinated app-container restart.
- Back up the persistent application storage and Redis volumes before upgrades or database migrations.
