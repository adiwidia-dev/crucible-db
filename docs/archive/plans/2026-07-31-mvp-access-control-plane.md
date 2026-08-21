# MVP Access Control Plane Implementation Plan

> **Historical record.** This July 2026 implementation plan is a frozen account of an earlier scope and does not describe current behavior, policy, UI, or deployment. Use the [README](../../../README.md), [product overview](../../product/overview.md), and [architecture decisions](../../architecture/decisions.md) for active guidance.

Date: 2026-07-31
Status: Self-approved

## Phase 1: Domain Foundation

- Add enums for access mode, database driver, query status, query type, execution status.
- Add migrations for roles, user role relation, database connections, role database permissions, query requests, query reviews, query executions, and audit logs.
- Add Eloquent models with relationships, fillable fields, casts, and factories.
- Add database seeder for default admin and developer roles.

## Phase 2: Security And Services

- Add policies for connection, role, query request, and audit log access.
- Add audit logger service.
- Add query classifier/guard service.
- Add database execution service with driver-specific config and result limits.
- Add query approval/execution service for lifecycle changes.

## Phase 3: Backend Workflow

- Add Form Requests for connection, role permission, query request, review, and schedule/execute actions.
- Add resource controllers for:
  - Database connections.
  - Role permissions.
  - Query requests.
  - Query reviews.
  - Audit logs.
- Add queued job for query execution.
- Add scheduled command to dispatch due approved queries.
- Wire routes under authenticated and verified middleware.

## Phase 4: Inertia UI

- Add sidebar navigation for Crucible sections.
- Add dashboard summary cards.
- Add database connection CRUD pages.
- Add role permission page.
- Add query request index/create/show pages with review and execution actions.
- Add audit log index page.
- Use Wayfinder-generated routes/actions, existing UI primitives, and responsive Tailwind layouts.

## Phase 5: Verification

- Run migrations in Docker.
- Regenerate Wayfinder output via Vite build.
- Add feature tests for permission gates, query lifecycle, execution, and audit logging.
- Run targeted tests during implementation.
- Run final `composer run ci:check`, production build, production npm audit, and `/health` check.
