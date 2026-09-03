# Production deployment and upgrades

Production uses `Dockerfile.production`, the Go 1.26.6-based `Dockerfile.native`, immutable matching application and native-proxy images, and `compose.production.yaml`. The moving `hephaestus/crucible-db:alpha` tag is retained for compatibility, but production installations should pin the release version.

## Before the first deployment

Prepare a deployment directory containing:

- `compose.production.yaml`
- `.env.production.example`
- a private `.env.production` generated from the example

Set a unique application key, public URL, and email settings in `.env.production`.

```bash
cp .env.production.example .env.production
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
```

Copy the generated key into `.env.production`. Do not commit this file.

Set `OCTANE_WORKERS` and `OCTANE_MAX_REQUESTS` only after measuring the host. The defaults are two workers and 500 requests per worker. The supplied container and Composer package requirement use PHP 8.5 or later.

## Start the stack

```bash
docker compose -f compose.production.yaml up -d
docker compose -f compose.production.yaml ps
curl --fail http://localhost:8000/health
```

The application entrypoint creates the SQLite file when needed, runs forward-only migrations, and starts Octane/FrankenPHP, Horizon, and the scheduler under Supervisor. Redis must pass its health check before the application starts. The Caddy gateway is the only public service; it routes normal application traffic to the app and fixed Native client discovery/tunnel paths to the private proxy. Compose reports the app as healthy only after `/health` confirms both the HTTP process and Redis-backed cache are available.

## Upgrade safely

1. Announce the maintenance window according to your operational policy.
2. Back up the persistent application storage and Redis volumes.
3. Compare the deployed environment with `.env.production.example`. For HTTPS deployments, keep `SESSION_SECURE_COOKIE=true`.
4. Pull the approved image through the production Compose file.
5. Recreate services and verify health and logs.

```bash
docker compose -f compose.production.yaml pull
docker compose -f compose.production.yaml up -d --remove-orphans
docker compose -f compose.production.yaml ps
docker compose -f compose.production.yaml logs --tail=100 app redis native-proxy gateway
curl --fail http://localhost:8000/health
```

!!! warning "Migrations are forward-only"
    The startup process runs migrations before the long-running services start. A storage and Redis backup is the recovery point for an unsuccessful upgrade. Do not invent a downgrade command.

## After an upgrade

Verify:

- `/health` returns successfully.
- The app and Redis services are healthy.
- Horizon workers and the scheduler are running inside the app container.
- The `native-proxy` and `gateway` services are running when Native client access is enabled; neither database listener has a host port mapping.
- Existing requests, notifications, and audit records are present.
- A safe read-only workflow can be created and reviewed.

See [Backup, recovery, and troubleshooting](operations.md) for symptoms and next steps.

See [Queues, workers, and schedules](queues-and-workers.md) for queue names, worker limits, and Horizon access.
