# Crucible DB MVP Access Control Plane

Date: 2026-07-31
Status: Self-approved for implementation

## Goal

Implement the first usable Crucible DB MVP as a database access control plane. The app must let authenticated users request SQL execution against managed database connections, let authorized reviewers approve or reject requests, execute approved requests immediately or on schedule, and record audit events for security review.

## Scope

- Role-based access at the database-connection level.
- CRUD database connections for MySQL and PostgreSQL targets.
- Role permission management for each connection:
  - Access mode: none, read, write.
  - Can review requests for that connection.
  - Requires approval before execution.
- Query request lifecycle:
  - Create request for a specific connection.
  - Classify query as read or write.
  - Enforce access mode.
  - Bypass approval only when role permission allows it.
  - Review approval/rejection.
  - Immediate execution or scheduled execution.
- Queue-backed execution with timeout and failure capture.
- Audit log for user-visible and security-significant actions.
- Inertia React UI for the MVP flow.

## Explicit Non-Goals

- Kubernetes exec.
- Complex table-level or column-level RBAC.
- SQL proxy protocol implementation.
- Full SQL parser. MVP uses conservative query classification and single-statement guard.
- External SSO. Traditional auth remains the active auth surface.

## Security Decisions

- Database connection passwords are stored with Laravel encrypted casts and hidden from serialized output.
- Controller actions must call policies/gates or Form Request authorization.
- Users can only create query requests for connections granted to their role.
- Write access is required for non-read SQL.
- Reviewer permission is connection-specific.
- Approval bypass is connection-specific through role permission.
- Execution jobs implement timeout, retry limits, and failure handling.
- SQL execution rejects empty SQL, multiple statements, and dangerous session/file/admin statements in MVP.
- Result capture is limited to a small sample to avoid storing large or sensitive result sets.
- Audit logs are append-only through application code.

## MVP Success Criteria

- Admin can create a connection and assign role permissions.
- Developer can submit a query request for an allowed connection.
- Requests requiring approval wait for reviewer action.
- Reviewer can approve or reject.
- Approved immediate request executes through the queue.
- Approved scheduled request is dispatched by the scheduler command.
- Execution result/error is visible on the request detail.
- Audit log shows key actions with actor, action, target, IP, and metadata.
- Feature tests cover authorization, approval bypass, review, execution, and audit creation.
