# Crucible DB Project Decisions

Last updated: 2026-07-31

## Product Direction

Crucible DB is a database access control plane. It manages who can access database connections, what they can execute, when execution can happen, who must approve it, and how every action is audited.

The product is inspired by Kviklet, but Crucible DB will focus on stronger scheduled execution, role-based approval bypass, and zero-trust auditability.

## Explicit Non-Goals For Now

- Kubernetes exec support.
- Complex table-level RBAC.
- Blade or Livewire frontend.
- PostgreSQL-compatible Turso frontend.
- Building a general BI/query dashboard.

## Approved MVP

1. Email/password authentication.
2. Users, roles, and permissions.
3. Database connection management for PostgreSQL and MySQL first.
4. Query execution lifecycle:
   - draft
   - pending review
   - approved
   - rejected
   - scheduled
   - running
   - succeeded
   - failed
   - cancelled
5. Approval rules per role and database connection.
6. Approval bypass for trusted roles, with mandatory reason and audit trail.
7. Scheduled query execution after approval when required.
8. Append-only audit logging.
9. Basic SQL result viewer.
10. Encrypted storage for database connection credentials.

## Planned Features Beyond MVP

- Temporary access sessions, such as approved access for 30 minutes or 1 hour.
- Policy engine based on role, database, environment, query type, and time window.
- Break-glass/trusted execution with reason, ticket URL, scope, expiration, and audit trail.
- Query risk classification for read, write, DDL, destructive statements, and dangerous keywords.
- Explain or dry-run step before approval where supported by the target database.
- Scheduled execution controls:
  - approve query content separately from execution time
  - allow cancel and reschedule
  - require re-approval if SQL changes
- Slack, Teams, or email notifications.
- Credential encryption and key rotation.
- API keys and service accounts for automation.
- Connection health checks and permission test queries.
- Environment labels such as production, staging, and development.

## Stack Decision

- Backend: Laravel 13.
- Frontend: Inertia + React.
- UI: Kumo UI.
- Styling: Tailwind CSS v4.
- Metadata database: Turso/libSQL using the SQLite-compatible frontend.
- Queue/cache/session recommendation: Redis.
- Web runtime: FrankenPHP through Laravel Octane.
- Target database drivers for managed connections: PostgreSQL and MySQL first.

Laravel 13 is a good fit because it provides first-class support for authentication, authorization through gates and policies, queues, scheduled jobs, database connections, migrations, and testing.

Kumo UI is a React component library, so the frontend should use Inertia + React instead of Blade or Livewire.

## Runtime Decision

Crucible DB should use FrankenPHP through Laravel Octane for the production web runtime.

The goal is to keep Laravel booted in long-lived workers for faster request handling while still using Laravel's normal application model. Octane supports a `frankenphp` server option, and FrankenPHP worker mode keeps the PHP application in memory between requests.

### Recommended Runtime Split

- FrankenPHP + Laravel Octane for HTTP requests.
- Redis-backed Laravel queues for scheduled query execution, notifications, and other background work.
- Laravel scheduler for dispatching due scheduled executions.
- Separate queue workers from web workers.

Long-running database query execution must not run inside normal HTTP request workers. HTTP endpoints should validate, authorize, create records, and dispatch jobs. Query execution jobs should run in queue workers with explicit timeout, retry, cancellation, and audit behavior.

### Octane Coding Rules

Because Octane and FrankenPHP use long-lived workers, application code must avoid leaking request-specific state between requests.

- Do not store current user, request, tenant, database connection, or authorization state in static properties or long-lived singletons.
- Do not mutate global state from request handlers.
- Be careful with singleton services that capture request objects or user-specific data.
- Use max-request worker recycling as a guard against memory leaks.
- Reload workers during deployments.
- Keep dynamic target database connections short-lived and explicitly disconnected after execution.

### Caveats

- FrankenPHP can make HTTP handling faster, but it does not remove the need for careful queue design.
- The biggest performance risks for Crucible DB are target database query duration, audit write volume, and policy evaluation correctness, not PHP request boot time alone.
- The first implementation should work correctly under normal PHP-FPM semantics too, then be verified under Octane/FrankenPHP for state-safety.

## Development And Deployment Baseline

Crucible DB should be developed and packaged with Docker and Docker Compose from the start.

The goal is reproducibility. A new contributor or deployment environment should be able to run the platform without manually installing PHP extensions, Node tooling, Redis, or target test databases on the host.

### Recommended Compose Services

- `app`: Laravel HTTP app running FrankenPHP/Octane.
- `worker`: Laravel queue worker or Horizon process.
- `scheduler`: Laravel scheduler process.
- `redis`: queues, cache, sessions, locks, and rate limiting.
- `node`: frontend asset development when useful, or build stage in the app image.
- `mailpit`: local email testing.
- `target-postgres`: local target database used to test connection management and query execution.
- `target-mysql`: local target database used to test connection management and query execution.

The metadata database does not need a separate service in the MVP when using local libSQL/SQLite-compatible storage. It should live in a mounted persistent volume.

Compose services should use named volumes for persistent data and health-gated dependencies where startup order matters.

## Development Workflow

Crucible DB development should incorporate the installed Impeccable and Superpowers workflows.

### Impeccable

Use Impeccable for UI and UX work, including product interface shaping, design critique, accessibility, responsive behavior, UI polish, design-system extraction, onboarding, empty states, and UX copy.

For Crucible DB, Impeccable should be treated as a product UI workflow, because the interface is an operational control plane where design serves repeated, high-stakes database access workflows.

Before substantial UI work, create or maintain:

- `PRODUCT.md`: product purpose, users, tone, anti-references, and strategic principles.
- `DESIGN.md`: colors, typography, layout rules, component decisions, and interaction principles.

### Superpowers

Superpowers is installed and enabled as `superpowers@openai-curated` version `d6169bef`.

Use Superpowers for development process discipline:

- brainstorming and shaping substantial features
- writing implementation plans
- test-driven development where behavior is clear
- systematic debugging
- verification before completion
- code review workflows
- finishing branches

Superpowers should guide how implementation work is planned, tested, debugged, and verified. It should not override explicit project decisions in this document.

### Production Shape

The production deployment should preserve the same process split:

- web container for FrankenPHP/Octane
- worker container for queue execution
- scheduler container for due jobs
- Redis service
- persistent metadata database storage or remote Turso/libSQL

This keeps deployment portable across a single VPS, Docker Compose, Nomad, ECS, Fly.io, or Kubernetes later without making Kubernetes part of the product's initial scope.

## Additional Stack Recommendations

### Keep

- Laravel 13 as the control-plane backend.
- Inertia + React for the frontend.
- Kumo UI for React components.
- Turso/libSQL SQLite-compatible metadata storage for the MVP.
- Redis as required infrastructure for queues, cache, sessions, locks, and scheduling coordination.
- FrankenPHP/Octane for HTTP runtime.

### Add Early

- Laravel Horizon for Redis queue visibility and worker supervision.
- Strict TypeScript on the React frontend.
- A dedicated SQL execution service layer, even if implemented inside Laravel initially.
- Advisory locks or Redis locks around scheduled execution dispatch to prevent duplicate execution.
- Structured JSON logs from all containers.
- Health endpoints for app, worker, scheduler, Redis, and metadata database access.

### Add Later Only If Needed

- A separate proxy/execution service in Rust or Go if the database proxy becomes performance-critical or protocol-heavy.
- Real-time execution progress updates through WebSockets or server-sent events.
- PostgreSQL or MySQL as an alternative metadata backend for larger multi-instance enterprise deployments.

### Avoid For Now

- Running scheduled query execution inside HTTP workers.
- Using the metadata database as the queue backend.
- Introducing Kubernetes-only deployment assumptions.
- Building a separate microservice architecture before the policy, approval, audit, and execution model is stable.

## Metadata Database Decision

Crucible DB will use Turso/libSQL through its SQLite-compatible mode for the application's own metadata database instead of requiring a separate PostgreSQL instance.

The main reason is operational simplicity. Crucible DB should be easy to run without forcing users to provision PostgreSQL just to store users, roles, approvals, audit events, and connection metadata.

### Recommended MVP Mode

Use local libSQL/SQLite-compatible storage for the MVP:

- local database file for single-node deployments
- encrypted application secrets for stored database credentials
- Redis for queues, cache, and sessions to reduce write contention on the metadata database

This keeps the MVP lightweight while still leaving room to support Turso remote databases or another SQL metadata backend later.

### Important Caveats

- SQLite/libSQL has different write-concurrency behavior than PostgreSQL. It is suitable for the MVP, but audit logging and queue writes should be designed carefully.
- Do not use the metadata database as the queue backend if Redis is available. Scheduled execution and audit logging can create write pressure.
- Multi-instance Crucible deployments need a deliberate metadata strategy, such as Turso remote/libSQL server or a future PostgreSQL/MySQL metadata option.
- Turso local sync server is useful for development and testing, but should not be treated as the default production sync architecture unless the project accepts that operational risk.
- Migrations and schema design should stay conservative and SQLite-compatible.

## Core Domain Objects

- User
- Role
- Permission
- DatabaseConnection
- DatabaseCredential
- DatabaseAccessPolicy
- QueryRequest
- QueryVersion
- QueryApproval
- ScheduledExecution
- ExecutionResult
- TemporaryAccessSession
- AuditEvent
- Notification

## Query Request Lifecycle

1. User creates a query request against a database connection.
2. Crucible classifies the query risk.
3. Policy engine determines whether approval is required.
4. If approval is required, reviewers approve or reject.
5. If approval is bypassed, the user must provide a reason and the event is audited.
6. Approved requests can be executed immediately or scheduled.
7. Scheduled execution dispatches through a queue job.
8. Execution result, affected rows, error details, timing, and actor are recorded.
9. Every state transition emits an append-only audit event.

## Audit Principles

- Audit events are append-only.
- Audit logs should record actor, action, target, timestamp, IP/device context, request metadata, before/after values where appropriate, query hash, execution status, affected rows, and error details.
- Approval bypass is allowed only as an explicit audited action.
- Changes to SQL after approval must invalidate the previous approval.
- Credential values must never appear in audit payloads.

## Reference Sources

- Kviklet: https://github.com/kviklet/kviklet
- Laravel 13 docs: https://github.com/laravel/docs/tree/13.x
- Laravel Octane: https://github.com/laravel/octane
- FrankenPHP: https://github.com/php/frankenphp
- Docker Compose: https://github.com/docker/compose
- Turso docs: https://github.com/tursodatabase/turso-docs
- Turso/libSQL PHP client: https://github.com/tursodatabase/turso-client-php
- Kumo UI: https://github.com/cloudflare/kumo
