# Production deployment and upgrades

Production uses `Dockerfile.production`, the Go 1.27.1-based `Dockerfile.native`, immutable matching application and native-proxy images, and `compose.production.yaml`. Moving tags such as `latest` and the legacy `alpha` tag are convenience channels; production installations must explicitly pin both images to one release version or exact digests.

## Before the first deployment

Prepare a deployment directory containing:

- `compose.production.yaml`
- `.env.production.example`
- a private `.env.production` generated from the example

Set a unique application key, initial setup token, HTTPS public URL, and email settings in `.env.production`.
Keep `CRUCIBLE_DATABASE_CONFIG_MODE=managed` when administrators should choose and
migrate the application database through Crucible. The encrypted selection and
migration records live in the persistent `crucible_storage` volume.

```bash
cp .env.production.example .env.production
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
printf 'CRUCIBLE_INITIAL_SETUP_TOKEN=%s\n' "$(openssl rand -hex 32)"
```

Before starting Compose, replace `<version>` with one published release and set
both required image variables in `.env.production`:

```dotenv
CRUCIBLE_IMAGE=hephaestus/crucible-db:<version>
CRUCIBLE_NATIVE_IMAGE=hephaestus/crucible-db-native:<version>
```

Use the same version for both images. Exact digests are also supported and give
the strongest reproducibility. Compose fails before startup if either variable
is empty, which prevents an accidental mixed-version or moving-tag deployment.

Copy both generated secret values into `.env.production`. Do not commit this
file. The setup token is required before the browser can choose a control
database or create the first administrator. Rotate or remove it after initial
setup; the completed-setup sentinel prevents the setup routes from reopening.

## Terminate TLS in front of the local origin

The application container includes the Caddy/FrankenPHP HTTP origin and binds
it to `127.0.0.1:8000` by default. It is intentionally not a public TLS server.
Remote deployments must place an administrator-managed TLS terminator such as
Nginx or Cloudflare Tunnel in front of that loopback listener. Keep `APP_URL`
set to the external `https://` origin and `SESSION_SECURE_COOKIE=true`.

The terminator must preserve `Host`, `X-Forwarded-For`,
`X-Forwarded-Proto=https`, and WebSocket upgrades. Do not apply buffering,
compression, or a short request timeout to
`/.well-known/crucible-native-client.json` or `/native-tunnel/*`. The embedded
Caddy configuration accepts forwarded headers only from private proxy peers
and routes those fixed native-client paths from the application origin to the
private native-proxy service.

Set `CRUCIBLE_BIND_ADDRESS` to a non-loopback address only when a separate
machine must reach the origin over a protected private network. Never publish
the origin directly to an untrusted network.

Set `OCTANE_WORKERS` and `OCTANE_MAX_REQUESTS` only after measuring the host. The defaults are two workers and 500 requests per worker. The supplied container and Composer package requirement use PHP 8.5 or later.

## Choose the application database topology

SQLite requires no additional service and remains the default for a single
application instance. Start the base stack, then choose SQLite during initial
setup.

For a Compose-managed PostgreSQL or MySQL database, set a strong
`CRUCIBLE_CONTROL_DATABASE_PASSWORD` in `.env.production`, enable exactly one
profile, wait for it to become healthy, and enter these values during initial
setup:

| Profile | Host | Port | Database and username |
| --- | --- | --- | --- |
| `control-postgres` | `control-postgres` | `5432` | Values from `CRUCIBLE_CONTROL_DATABASE_NAME` and `CRUCIBLE_CONTROL_DATABASE_USERNAME` |
| `control-mysql` | `control-mysql` | `3306` | Values from `CRUCIBLE_CONTROL_DATABASE_NAME` and `CRUCIBLE_CONTROL_DATABASE_USERNAME` |

```bash
docker compose --env-file .env.production -f compose.production.yaml --profile control-postgres up -d
# or
docker compose --env-file .env.production -f compose.production.yaml --profile control-mysql up -d
```

Neither database service publishes a host port. For an externally managed
database, leave both profiles disabled and enter its private endpoint during
initial setup.

`CRUCIBLE_DATABASE_CONFIG_MODE=environment` is intended for installations where
deployment automation owns the database connection. In that mode, set the
`DB_*` variables directly; the admin migration page is informational and
cutover remains a deployment operation.

## Start the stack

```bash
docker compose --env-file .env.production -f compose.production.yaml up -d
docker compose --env-file .env.production -f compose.production.yaml ps
curl --fail http://127.0.0.1:8000/health
```

The application entrypoint creates the SQLite file when needed, waits for the
selected database, runs forward-only migrations, and starts Octane/FrankenPHP,
Horizon, and the scheduler under Supervisor. Redis must pass its health check
before the application starts. The app is the only host-published service and
its embedded Caddy/FrankenPHP origin remains loopback-only. It serves normal
application traffic and routes the fixed Native client discovery/tunnel paths
to the private proxy. Compose reports the app as healthy only after `/health`
confirms the control database and Redis-backed cache are available.

## Migrate an existing installation

Open **Manage → Administration → Application → Database**, create a plan for a dedicated empty
destination, and follow the copy, verification, and cutover steps. Before copy
or rollback, Crucible requires zero active query sessions, native leases, and
native connections, then drains queued work and engages a maintenance fence.

When the page says a restart is required, restart the application container so
Octane, Horizon, and the scheduler all load the encrypted connection selection:

```bash
docker compose --env-file .env.production -f compose.production.yaml restart app
docker compose --env-file .env.production -f compose.production.yaml ps
curl --fail http://127.0.0.1:8000/health
```

Return to the migration page and finalize only after the app is healthy. The
page checks the running web process's database fingerprint and keeps the button
disabled until it matches the prepared destination. `/health` remains available
while the maintenance fence is active so the control database and Redis can be
verified. The native proxy may report unhealthy during this window because its
signed control checks are intentionally blocked; it should recover after
finalization releases the fence.

## Upgrade safely

1. Announce the maintenance window according to your operational policy.
2. Back up the persistent application storage and Redis volumes.
3. Compare the deployed environment with `.env.production.example`. Keep the public `APP_URL` on HTTPS and `SESSION_SECURE_COOKIE=true`.
4. Update `CRUCIBLE_IMAGE` and `CRUCIBLE_NATIVE_IMAGE` to the same approved release version or exact digests.
5. Pull the approved images through the production Compose file.
6. Recreate services and verify health and logs.

```bash
docker compose --env-file .env.production -f compose.production.yaml pull
docker compose --env-file .env.production -f compose.production.yaml up -d --remove-orphans
docker compose --env-file .env.production -f compose.production.yaml ps
docker compose --env-file .env.production -f compose.production.yaml logs --tail=100 app redis native-proxy
curl --fail http://127.0.0.1:8000/health
```

!!! warning "Migrations are forward-only"
    The startup process runs migrations before the long-running services start. A storage and Redis backup is the recovery point for an unsuccessful upgrade. Do not invent a downgrade command.

## After an upgrade

Verify:

- `/health` returns successfully.
- The app and Redis services are healthy.
- Horizon workers and the scheduler are running inside the app container.
- The `native-proxy` service is running when Native client access is enabled; neither native database listener has a host port mapping.
- The app is the only host-published service, and its HTTP origin remains bound to the intended interface (loopback by default).
- Existing requests, notifications, and audit records are present.
- A safe read-only workflow can be created and reviewed.

See [Backup, recovery, and troubleshooting](operations.md) for symptoms and next steps.

See [Queues, workers, and schedules](queues-and-workers.md) for queue names, worker limits, and Horizon access.
