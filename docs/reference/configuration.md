# Configuration reference

Production loads its configuration from `.env.production` through `compose.production.yaml`. Keep this file private and do not commit it.

## Compose-managed values

| Value | Purpose |
| --- | --- |
| `CRUCIBLE_IMAGE` | Overrides the application image. Defaults to `hephaestus/crucible-db:alpha`. |
| `CRUCIBLE_ENV_FILE` | Selects the environment file. Defaults to `.env.production`. |
| `APP_ENV` | Set by Compose to `production`. |
| `APP_DEBUG` | Set by Compose to `false`. |
| `DB_CONNECTION` | Set by Compose to `sqlite`. |
| `DB_DATABASE` | Set by Compose to the persistent `/app/storage/database/crucible.sqlite` path. |
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
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | Application localization defaults. |
| `BCRYPT_ROUNDS` | Password hashing work factor. |
| `LOG_CHANNEL`, `LOG_LEVEL` | Container log destination and minimum severity. |
| `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_DOMAIN` | Browser session duration, encryption, and cookie scope. |
| `REDIS_PASSWORD`, `REDIS_PORT` | Redis client settings; Compose supplies the internal host. |
| `MAIL_*` | SMTP transport, credential, encryption, and sender identity. |
| `OCTANE_WORKERS` | FrankenPHP request-worker count. Defaults to `2`. |
| `OCTANE_MAX_REQUESTS` | Requests served before an Octane worker recycles. Defaults to `500`. |

Do not override the Compose-managed database, Redis, queue, or session settings unless you deliberately redesign and validate the deployment topology.

## Security handling

- Generate a unique `APP_KEY` for each installation.
- Restrict filesystem permissions for `.env.production`.
- Use a TLS-terminating reverse proxy or an appropriate protected network boundary for the application port.
- Keep production logs and volume backups in approved, access-controlled storage.
