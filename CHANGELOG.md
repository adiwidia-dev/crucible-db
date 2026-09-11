# Changelog

All notable changes to Crucible DB are documented in this file. The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- SQLite, PostgreSQL, and MySQL application-database drivers for Crucible control-plane data, including driver selection during initial setup.
- Encrypted, resumable application-database migration plans with independent verification, maintenance fencing, restart-aware cutover, and data-preserving rollback.
- An admin-only **Application Database** workflow with connectivity validation, progress and activity reporting, typed confirmations, and recovery actions.
- Optional isolated `control-postgres` and `control-mysql` production Compose profiles; external managed database endpoints remain supported without enabling either profile.
- A compact, independently collapsible Administration and Account navigation structure with explicit Access & identity, Security & policy, Application, and Governance categories.

### Security

- Block normal web, queued, scheduled, and native-client control activity during application-database copy, cutover, and rollback while retaining an authenticated admin recovery path.
- Keep application-database credentials only in encrypted configuration and migration files and omit them from browser props, audit metadata, and command inspection output.
- Require a deployment-controlled token before first-run database selection or owner creation, preserve a completed-setup sentinel, and serialize initial ownership creation.
- Encrypt and authenticate native proxy control request and response payloads, reject MySQL executable comments and optimizer hints, sanitize migration failures, and neutralize spreadsheet formulas in CSV exports.
- Bind bundled HTTP origins and development service ports to loopback by default while documenting administrator-managed TLS termination and trusted proxy boundaries.

### Operations

- Verify the control database and Redis cache in application readiness checks.
- Wait for the selected application database before startup migrations and document the required Octane, Horizon, and scheduler restart boundary.
- Add corruption, invalid-credential, partial-copy resume, source-mutation, cross-driver, and native-client regression coverage.

## [0.1.0] - 2026-08-24

### Added

- Governed Deployment Batches with ordered, connection-scoped SQL statements, optional scheduling, approval, per-statement execution records, cancellation, and linked retry workflows.
- Non-executable Deployment Batch drafts that can retain blocked preflight results and run preflight again before strict submission.
- Time-bounded Query Access sessions with read-only or explicitly authorized read + write levels, multi-connection scope, schema browsing, query history, keyboard execution, and CSV result exports.
- Role-based access policies for explicit connection groups and individual connection exceptions, including ordered role precedence, independent read/write approval requirements, reviewer authority, Query Access capability, and write-session duration limits.
- Workspace-governed SQL statement families for reads, `INSERT`, `UPDATE`, `DELETE`, `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, and `TRUNCATE TABLE`.
- CTE-aware top-level SQL classification and conservative preflight warnings for unbounded reads and writes.
- Audited Emergency SQL fallback for a single otherwise unsupported Deployment Batch statement, while administrative, file-access, security-management, procedural, transaction-control, multi-statement, and `EXPLAIN ANALYZE` SQL remain blocked.
- Invitation-only authentication with password reset, passkeys, two-factor authentication, recovery codes, and invitation-gated Google, GitHub, and Microsoft SSO.
- In-app and optional email notifications, resource watching, notification history, operational recipients, and filterable/exportable audit logs.
- PostgreSQL and MySQL connection management, encrypted credentials, health tests, and explicit connection groups.
- Production Docker topology using FrankenPHP/Laravel Octane, Horizon, the Laravel scheduler, Redis, and persistent SQLite application metadata.
- A complete role-based documentation site with user, reviewer, administrator, operator, deployment, security, and release guidance.

### Security

- Enforced authorization and validation server-side for administrative, request, review, execution, export, session, notification, and connection actions.
- Encrypted target database credentials, SSO provider secrets, and sensitive application settings.
- Isolated Query Access read transactions and current-policy enforcement on every session query.
- Updated transitive `nanoid` from `3.3.16` to `3.3.18` to resolve `GHSA-2v37-7h3g-55p8`.
- Updated transitive `js-yaml` from `4.3.0` to `4.3.1` to resolve `GHSA-mh29-5h37-fv8m` in the development toolchain.

### Operations

- Added immutable `hephaestus/crucible-db:0.1.0` and moving `hephaestus/crucible-db:alpha` image channels.
- Added OCI image metadata for version, source, documentation, license, and revision.
- Added health checks, forward-only startup migrations, documented volume backups, queue isolation, worker recycling, and session-expiry scheduling.
- Enabled HTTPS-only production session cookies and Redis-aware application health reporting in Docker Compose.
- Added an MIT license file and private vulnerability-disclosure policy for the public repository.

### Known constraints

- The supplied production Compose topology is single-node and stores application metadata in a local persistent SQLite volume.
- Query Access is browser-based; Crucible DB is not a PostgreSQL/MySQL protocol proxy for external database clients.
- Deployment Batches are sequential and stop at the first failure, but are not user-controlled atomic transactions.
- Production Horizon dashboard access is denied by default until trusted operator identities are configured.

[Unreleased]: https://github.com/adiwidia-dev/crucible-db/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/adiwidia-dev/crucible-db/releases/tag/v0.1.0
