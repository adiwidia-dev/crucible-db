# Crucible DB

Crucible DB is a database access control plane for teams that need SQL work to be reviewed, scheduled, proxied, and audited without handing out direct database credentials.

## What it provides

- Role-based access to PostgreSQL and MySQL connections.
- Reviewed and scheduled query execution with a durable audit trail.
- SSO and password-based authentication, including passkeys and two-factor authentication.
- A Laravel Octane web runtime, Horizon workers, scheduler, Redis, and SQLite-compatible metadata storage.

## Requirements

- PHP 8.5
- Node.js 22
- Composer 2
- Docker and Docker Compose for the containerized runtime

## Local development

```bash
composer setup
composer dev
```

The development stack is also available through Docker Compose:

```bash
docker compose up --build
```

## Production deployment

1. Copy `.env.production.example` to a secure environment file and set a unique `APP_KEY`, `APP_URL`, and mail credentials.
2. Build and run the production stack:

   ```bash
   docker compose -f compose.production.yaml up --build -d
   ```

3. Confirm the health endpoint is available at `/up`.

The production Compose stack runs migrations first, then starts separate Octane, Horizon, scheduler, and Redis services. During code deployments, run `php artisan optimize` and gracefully reload long-running services with `php artisan reload` (or `php artisan octane:reload` for Octane-only reloads).

## Verification

```bash
composer ci:check
```

## Documentation

- [Product overview](docs/product/overview.md)
- [Design direction](docs/product/design.md)
- [Architecture decisions](docs/architecture/decisions.md)
- [Historical plans](docs/archive/plans/)
- [Historical specifications](docs/archive/specifications/)

## License

License selection is pending before the first public release.
