# Configuration reference

Production loads application configuration from `.env.production` through `compose.production.yaml`. Compose also interpolates native-proxy values before containers start, so invoke production commands with `--env-file .env.production` (or export the same values in the shell). Keep this file private and do not commit it.

## Compose-managed values

| Value | Purpose |
| --- | --- |
| `CRUCIBLE_IMAGE` | Overrides the application image. The release Compose file defaults to `hephaestus/crucible-db:0.1.0`. Set it in the shell or a Compose `.env` file, because service `env_file` values do not control Compose interpolation. |
| `CRUCIBLE_NATIVE_IMAGE` | Required private native-proxy image. Set an immutable image that matches the application release; Compose deliberately has no fallback so it cannot silently start an older proxy. |
| `CRUCIBLE_ENV_FILE` | Selects the environment file. Defaults to `.env.production`. |
| `APP_ENV` | Set by Compose to `production`. |
| `APP_DEBUG` | Set by Compose to `false`. |
| `CRUCIBLE_CONTROL_DATABASE_NAME`, `CRUCIBLE_CONTROL_DATABASE_USERNAME`, `CRUCIBLE_CONTROL_DATABASE_PASSWORD` | Provision the optional `control-postgres` or `control-mysql` profile. The password must be set before either profile is enabled. |
| `QUEUE_CONNECTION` | Set by Compose to `redis`. |
| `SESSION_DRIVER` | Set by Compose to `redis`. |
| `REDIS_HOST` | Set by Compose to the internal `redis` service. |

## Environment file responsibilities

The production environment file supplies the application key, public URL, mail delivery settings, and any additional application configuration documented in `.env.production.example`.

| Environment value | Purpose |
| --- | --- |
| `APP_NAME` | Default application identity before workspace settings override it. |
| `APP_KEY` | Required encryption key for credentials, provider secrets, settings, sessions, and other encrypted values. |
| `APP_URL` | Public origin used to generate invitation, password reset, SSO callback, and application links. |
| `CRUCIBLE_INITIAL_SETUP_TOKEN` | One-time deployment secret (at least 32 characters) required before initial database selection or first-administrator creation. |
| `CRUCIBLE_BIND_ADDRESS`, `CRUCIBLE_HTTP_PORT` | Host binding for the bundled HTTP origin. Keep the address on `127.0.0.1` behind an administrator-managed TLS terminator. |
| `TRUSTED_PROXIES` | Comma-separated private proxy networks allowed to supply forwarded host, client, port, and HTTPS scheme headers. |
| `CRUCIBLE_DATABASE_CONFIG_MODE` | `managed` lets initial setup and the administrator migration UI own the encrypted selection. `environment` uses `DB_*` and makes the UI read-only. |
| `CRUCIBLE_DATABASE_CONFIG_FILE` | Persistent encrypted managed database selection. Defaults to `storage/app/crucible/application-database.enc`; production uses the corresponding absolute path in `crucible_storage`. |
| `CRUCIBLE_DATABASE_MIGRATION_DIRECTORY` | Persistent encrypted migration-plan history and progress records. |
| `CRUCIBLE_DATABASE_MIGRATION_FENCE_FILE` | Filesystem maintenance fence used to block mutations across copy, cutover, and rollback. Keep it outside every migratable database. |
| `DATABASE_STARTUP_ATTEMPTS` | Number of two-second startup migration attempts before the application container exits. Defaults to `30`. |
| `DB_CONNECTION`, `DB_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Fallback connection before a managed selection exists, and the authoritative control connection in `environment` mode. Supported drivers are `sqlite`, `pgsql`, and `mysql`. |
| `DB_SSLMODE` | PostgreSQL control-database SSL mode. Defaults to `prefer`; production network databases should use the verification mode required by the operator. |
| `MYSQL_ATTR_SSL_CA` | Optional CA path inside the application container for a MySQL control database. |
| `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS`, `DB_TRANSACTION_MODE` | SQLite contention and durability controls. Defaults are `5000`, `WAL`, `FULL`, and `IMMEDIATE`. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | Application localization defaults. |
| `BCRYPT_ROUNDS` | Password hashing work factor. |
| `LOG_CHANNEL`, `LOG_LEVEL` | Container log destination and minimum severity. |
| `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN` | Browser session duration, encryption, HTTPS-only transport, and cookie scope. Keep `SESSION_SECURE_COOKIE=true` when `APP_URL` uses HTTPS. |
| `REDIS_PASSWORD`, `REDIS_PORT` | Redis client settings; Compose supplies the internal host. |
| `MAIL_*` | SMTP transport, credential, encryption, and sender identity. |
| `OCTANE_WORKERS` | FrankenPHP request-worker count. Defaults to `2`. |
| `OCTANE_MAX_REQUESTS` | Requests served before an Octane worker recycles. Defaults to `500`. |
| `NATIVE_PROXY_ENABLED` | Enables Native client Query Access in the workspace deployment. Keep it `true` only when the native-proxy service is present. |
| `NATIVE_PROXY_CONTROL_SECRET` | Required long random shared secret for the proxy-to-Laravel control API. Do not reuse `APP_KEY` or expose this value to clients. |
| `NATIVE_PROXY_CONTROL_ENCRYPTED_RESPONSES_REQUIRED` | Requires authenticated AES-256-GCM control responses. Production and development Compose force this to `true`; keep application and proxy images on the same release. |
| `NATIVE_PROXY_ALLOWED_IDS` | Comma-separated proxy instance IDs allowed to call the internal control API. Compose sets this to `NATIVE_PROXY_ID`. |
| `NATIVE_PROXY_ALLOWED_IPS` | Optional comma-separated source-IP allowlist for internal control calls. Evaluation uses the direct peer address rather than forwarded client headers. |
| `NATIVE_PROXY_MAX_CONNECTIONS` | Distributed global ceiling reserved by Laravel before upstream credentials are released. Defaults to `100`. Configure the same value on every proxy replica. |
| `NATIVE_PROXY_MAX_CONNECTIONS_PER_LEASE` | Per-lease connection ceiling enforced by Laravel and again in the proxy process. Defaults to `3`. |
| `NATIVE_PROXY_MAX_CONNECTIONS_PER_USER` | Distributed per-user ceiling reserved by Laravel before upstream credentials are released. Defaults to `10`. Configure the same value on every proxy replica. |
| `NATIVE_PROXY_ID` | Stable operator-visible identifier for a proxy instance. |
| `NATIVE_PROXY_REVOCATION_CHANNEL` | Redis channel for lease revocation; defaults to `native-proxy:lease-revoked`. |
| `NATIVE_PROXY_IDLE_TIMEOUT` | Maximum inactivity on a PostgreSQL or MySQL client socket before the proxy closes it. Defaults to `15m`. |
| `NATIVE_PROXY_DRAIN_TIMEOUT` | Maximum time allowed for active database sessions to finish after shutdown begins. Defaults to `30s`; keep the container stop grace period longer than this value. |
| `NATIVE_PROXY_READINESS_INTERVAL` | Interval for signed Laravel control-plane and Redis dependency checks. Defaults to `5s`. |
| `NATIVE_PROXY_HEALTH_URL` | Internal readiness endpoint used by the scheduler. Production Compose sets `http://native-proxy:8081/readyz`. |
| `NATIVE_PROXY_EXPECTED_VERSION` | Optional expected proxy release version. A mismatch produces an operator alert. |
| `NATIVE_PROXY_CLI_DOWNLOAD_URL` | Release or mirror page opened by the Native Client Access workspace. Defaults to the official GitHub Releases page and can be replaced for forks or private artifact mirrors. |
| `NATIVE_PROXY_MAX_PENDING_DEVICE_AUTHORIZATIONS_PER_LEASE` | Maximum pending browser authorization challenges per lease. Defaults to `3`. |
| `NATIVE_PROXY_CONNECTION_STALE_SECONDS` | Fail an active connection whose durable heartbeat/activity is older than this value. Defaults to `60` seconds. |
| `NATIVE_PROXY_STATEMENT_STALE_SECONDS` | Fail a running native statement that has no completion record after this value. Defaults to `900` seconds. |

Do not override the Compose-managed Redis, queue, or session settings unless you deliberately redesign and validate the deployment topology. Keep every Laravel runtime on the same application-database selection; managed cutover requires a coordinated restart.

## Security handling

- Generate a unique `APP_KEY` for each installation.
- Restrict filesystem permissions for `.env.production`.
- Use a TLS-terminating reverse proxy or an appropriate protected network boundary for the application port.
- Keep production logs and volume backups in approved, access-controlled storage.
- Do not publish native PostgreSQL, MySQL, metrics, or proxy gateway ports. The supplied Caddy gateway routes only fixed discovery and tunnel paths through the single application origin.
- The production proxy receives only its public-origin, control, Redis, identity, limit, and timeout values. Do not add the application `env_file` to that service; doing so would expose unrelated encryption, mail, and SSO secrets to the proxy process.
