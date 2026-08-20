# Initial Scaffold Design

> **Historical record.** This July 2026 specification describes the original scaffold, not the current product or deployment. Use the README and current product and architecture documents for active guidance.

Date: 2026-07-31

## Purpose

Create the first runnable Crucible DB application scaffold in the repository root. This slice establishes the framework, development runtime, Laravel-specific tooling, and reproducible Docker environment without implementing the full database access workflow yet.

## Scope

This scaffold includes:

- Laravel 13 application in the repository root.
- Official Laravel React/Inertia starter kit with TypeScript authentication UI.
- Kumo UI integration path for future product screens.
- Laravel Boost installed after the Laravel application exists.
- Docker and Docker Compose baseline.
- FrankenPHP through Laravel Octane for the HTTP runtime.
- Redis for queues, cache, sessions, locks, and rate limiting.
- Local SQLite-compatible metadata database storage, structured to become libSQL/Turso-ready.
- Local target PostgreSQL and MySQL services for later connection-management testing.
- Mailpit for local mail verification flows.
- Basic health endpoint or health page.

This scaffold does not include role management, database connection CRUD, query execution, approvals, scheduling, proxy behavior, audit domain tables, or Google SSO. Those belong to later feature specs.

## Architecture

The repository root will become the Laravel application root. The app will use Laravel's React/Inertia starter kit as the base because Crucible DB needs a product-grade operational UI, not a Blade or Livewire interface.

The runtime will be split by process responsibility:

- `app`: HTTP app running Laravel under FrankenPHP/Octane.
- `worker`: Redis queue worker or Horizon process.
- `scheduler`: Laravel scheduler process.
- `redis`: queue, cache, sessions, locks, and rate limiting.
- `mailpit`: local mail capture.
- `target-postgres`: managed target database for development/testing.
- `target-mysql`: managed target database for development/testing.

The metadata database starts as a local SQLite-compatible file in a persistent volume. The configuration should keep the path open for the Turso/libSQL PHP driver, but the first scaffold should not block on native extension friction.

## Laravel Boost

Laravel Boost should be installed immediately after the Laravel app scaffold is generated. Boost will provide Laravel-specific guidelines, MCP tooling, and documentation-aware assistance for future implementation work.

The expected install flow is:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

Boost installation should be committed as part of the scaffold once it succeeds.

## Data Flow

For the first scaffold:

1. A user opens the app through the FrankenPHP/Octane HTTP container.
2. Inertia serves React/TypeScript pages.
3. Auth flows store application data in the local metadata database.
4. Redis handles queue, cache, session, lock, and rate-limiting infrastructure.
5. Worker and scheduler containers are present but initially have no Crucible-specific jobs beyond framework defaults.

Future query execution will be dispatched through queues, not handled inside HTTP workers.

## Error Handling

The scaffold should fail early when required services are unavailable:

- Compose dependencies should use health checks where practical.
- App startup should surface missing environment variables clearly.
- Redis connection failures should be visible during local startup.
- Target PostgreSQL and MySQL are development targets only; the main app should not require them to boot.

## Testing And Verification

The scaffold is complete when:

- Composer dependencies install.
- Frontend dependencies install.
- Laravel app key is generated.
- Migrations run against local SQLite-compatible metadata storage.
- Docker Compose services start.
- The HTTP app responds locally.
- Auth starter pages render.
- Redis is reachable by Laravel.
- Basic Laravel tests pass.

## Development Workflow

Use Superpowers for planning, TDD, debugging, and verification workflows. Use Impeccable before substantial product UI work. Before UI feature work begins, maintain `docs/product/overview.md` and `docs/product/design.md` so Impeccable can load project context.

Use Context7 or Laravel Boost documentation tools for Laravel, Boost, Kumo UI, Docker, and other package-specific questions.

## Open Decisions

There are no open decisions for the initial scaffold. The user approved the recommended choices:

- scaffold directly in the repository root
- use React/Inertia rather than Blade or Livewire
- use Redis from day one
- use local SQLite-compatible metadata storage first
- install Laravel Boost
- use Docker Compose
- start with scaffold, auth, infrastructure, and health before domain features
