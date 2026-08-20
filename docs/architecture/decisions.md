# Crucible DB Architecture Decisions

Last updated: 2026-08-20

## Current product boundary

Crucible DB is a governed database operations control plane. It manages role-scoped PostgreSQL and MySQL connections, reviewed deployment batches, time-bounded query-access sessions, execution history, audit records, connection health, and operational notifications.

The application does not currently implement a database protocol proxy for external tools such as pgAdmin. Query Access is an in-application, browser-based SQL workspace with an explicit read-only or read + write session level.

## Runtime and deployment

- **Backend:** Laravel 13 on PHP 8.3+.
- **Frontend:** Inertia v3 with React 19 and TypeScript.
- **Styling:** Tailwind CSS v4 and the local Crucible design system.
- **Production HTTP runtime:** FrankenPHP through Laravel Octane.
- **Background work:** Laravel Horizon backed by Redis.
- **Scheduling:** `php artisan schedule:work`.
- **Application metadata:** SQLite at `/app/storage/database/crucible.sqlite` in the persistent `crucible_storage` Docker volume.
- **Target drivers:** PostgreSQL and MySQL.

`Dockerfile.production` produces a single application image. Its Supervisor process starts three managed processes in that container: Octane/FrankenPHP on port 8000, Horizon, and the scheduler. `compose.production.yaml` pairs that application service with Redis and persists both application storage and Redis data in named volumes.

The production entrypoint runs database migrations before Supervisor starts. Migrations are forward-only, so operators must back up the persistent storage volume before an upgrade.

## Long-running worker safety

Octane workers keep Laravel booted between requests. Application code must not retain user, request, authorization, or dynamic target-connection state in static properties or long-lived singletons. Configuration or code changes require a container restart so workers boot the current version. Target database connections are created only for the authorized operation and must be released after use.

## Access policy model

Connection policy is assigned through roles. Each role + connection entry defines:

- maximum access: none, read, or write;
- reviewer authority;
- whether reads require approval;
- whether writes require approval; and
- an optional maximum duration for write-enabled sessions.

Users can hold multiple roles. The ordered **Policy precedence** list determines which role supplies an overlapping policy; the first applicable role wins. Users cannot approve their own requests, even when a role grants reviewer authority.

For a deployment batch, approval is evaluated from every selected statement connection and query type: any statement that requires approval causes the request to require approval. For Query Access, the requester declares read-only or read + write access before the session starts. That declared access level is checked against every selected connection and enforced on every session query.

## Query request workflows

### Deployment Batch

A Deployment Batch contains one or more ordered, single SQL statements. Each statement has its own target connection. The workflow is created, reviewed when policy requires it, optionally scheduled, dispatched through the `queries` queue, and executed in order. Execution stops at the first failed statement; completed and failed statements remain visible and locked in the request record.

Changes to an approved batch invalidate that approval. Eligible work can be cancelled with a reason. A failed deployment can be retried: read-only retries can be dispatched from the failed statement; write-impacting retries create a linked request for fresh policy evaluation and approval.

Scheduled batches do not run late without an explicit dispatch. If the requested time has passed while awaiting review, approving the batch leaves it ready for an authorized user to start rather than executing it unexpectedly.

### Query Access

A Query Access request selects one or more connections and a session duration. An approved request can start an in-application session. The session loads schema for the active selected connection, records every executed query, and expires or ends explicitly. Read-only sessions block data-changing SQL; read + write sessions require write access and policy approval where applicable. Ended or cancelled sessions can create a linked access request with the same scope; approval is re-evaluated from current policy.

## Deployment preflight

Deployment Batches receive a per-statement preflight report during creation and editing and again immediately before dispatch. The report distinguishes:

- **Ready:** no findings;
- **Ready with warnings:** execution may continue, but the requester and reviewer should assess the finding; and
- **Blocked:** a definite SQL, policy, target, or schedule safety failure prevents approval or execution.

Current warnings include unbounded `SELECT` statements and `UPDATE`/`DELETE` statements without `WHERE`. Administrative, file, and destructive DDL are rejected by the SQL safety guard. A blocked scheduled batch stays approved but undispatched and emits notifications to relevant people.

## Notifications and auditability

The application emits in-app database notifications for approval decisions, review requests, execution outcomes, query-session lifecycle changes, connection test failures, cancellation, retry, and preflight blocks. Users can watch a request or connection for resource-specific updates. Email delivery is optional and controlled by both workspace settings and individual user preferences. Notifications are surfaced in the application bell menu and notification history.

Meaningful workflows are also written to audit records. Stored connection credentials are encrypted; credential values must never be exposed through UI, notifications, or audit payloads.

## Operational constraints

- The SQLite metadata database is intended for the single-node Compose deployment provided here. Multi-node production deployments need a deliberate shared metadata strategy before scaling horizontally.
- Redis is required for queues, Horizon metadata, cache, and sessions; do not substitute the metadata database as the queue backend.
- Long-running target queries run in queued jobs, not in HTTP workers.
- Kubernetes, service-account automation, table-level RBAC, break-glass access, and external SQL-client proxying are not current capabilities.
