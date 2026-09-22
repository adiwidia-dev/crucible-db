# Changelog

All notable changes to Crucible DB are documented in this file. The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.10] - 2026-09-22

### Fixed

- Allow administrators to delete cancelled Native Client Query Access requests by cascading their dependent native-proxy leases, connections, device authorizations, tokens, and authentication attempts.

## [0.2.9] - 2026-09-22

### Changed

- Consolidate the Overview dashboard into three operational summary groups and one prioritized, filterable queue for reviews, SQL policy reviews, failures, scheduled executions, and active Query Access sessions.
- Give standalone data registries and table sections complete borders and rounded corners at phone widths as well as larger breakpoints.

### Fixed

- Keep the redesigned dashboard usable while long-running Octane workers still serve the previous Overview payload during a rolling deployment.

## [0.2.8] - 2026-09-18

### Fixed

- Remove the build-time Wayfinder contract from System Status so production images do not report an unhealthy runtime component after generated TypeScript has already been compiled into frontend assets.
- Keep Native Proxy health visible throughout the authenticated application and compact the mobile header by hiding redundant account text while retaining the avatar and actions.

## [0.2.7] - 2026-09-18

### Added

- Add an administrator-only System Status page with read-only, scheduler-refreshed snapshots for the application runtime, control database, Redis, Horizon, scheduler, native proxy, and generated Wayfinder contracts.

### Changed

- Document the System Status operational model, scheduler lifecycle, and canonical CLI `--help` and `--version` entry points.
- Make the CLI root `--version` output a clean release string suitable for scripts and interactive verification.

### Fixed

- Accept Deployment Batch statements up to 1,000,000 characters, store them safely in MySQL and PostgreSQL control databases, and surface statement-specific validation feedback in the editor.
- Isolate the PHPUnit setup token from Docker's inherited deployment token so setup-flow tests use the same configuration locally and in CI.

## [0.2.6] - 2026-09-17

### Added

- Let developers explicitly request administrator SQL-policy review for structurally reviewable unsupported Deployment Batch SQL while atomically preserving the batch as a non-executable draft.
- Surface pending policy-review work in the administrator dashboard, SQL Policy navigation badge, notifications, and requester-visible decision history.
- Re-evaluate current policy against the exact SQL and target in the Deployment Batch editor so draft editing reflects an administrator's allow or deny decision without needing a stale client-side policy snapshot.

### Changed

- Keep SQL-policy decisions separate from Deployment Batch approval, scheduling, and execution. An allow decision refreshes preflight; the requester must still submit the draft through the normal governed workflow.
- Require the narrowest available administrator decision: exact rule, parser-proven reusable shape, denial, or dismissal with an actionable decision note where required.

### Fixed

- Preserve request revisions in request details so reviewers can submit approvals or rejections with optimistic-concurrency protection and clear validation feedback.

## [0.2.5] - 2026-09-17

### Fixed

- Restore review submission for both Deployment Batches and Query Access requests by including the required request revision in the detail-page response.
- Display validation feedback when a review cannot be submitted, including when the request changed after the reviewer opened it.

## [0.2.4] - 2026-09-17

### Fixed

- Preserve the verified initiating client IP address for Deployment Batch audit events emitted by queued or scheduled execution, instead of attributing those events to a worker loopback address.
- Make the Workspace identity, SMTP delivery, and Factory reset sections use a consistent full-width layout in Application settings.

### Changed

- Update the development and production frontend build stages to Node.js 22 and refresh the JavaScript and native PostgreSQL client dependencies.

## [0.2.3] - 2026-09-16

### Fixed

- Keep native-proxy readiness visible on the dashboard before the first scheduler report and after a stale cache entry expires instead of incorrectly treating the feature as disabled.
- Give the development scheduler the same native-proxy health configuration as the application so its minute-level readiness refresh reaches the private proxy endpoint.
- Retain health snapshots long enough to bridge scheduler intervals without the dashboard briefly losing proxy status.

### Changed

- Move native-proxy readiness, reported version, active instance count, and connection count into a compact header indicator beside the Crucible CLI link.
- Validate that the README release badge matches the package version in CI and before release publication.
- Synchronize the README, MkDocs release metadata, changelog, and versioned release notes with the current release.

## [0.2.2] - 2026-09-12

### Security

- Exclude ignored developer-local artifacts, generated documentation output, browser state, scanner configuration, and native build output from the production Docker context.
- Prevent stale local binaries and tools from being copied into the production application image while reducing the locally verified image size without removing runtime features.

### Operations

- Keep native test-image SBOMs as workflow artifacts rather than attaching them from the read-only native test gate.
- Document the official Homebrew cask installation path while retaining signed GitHub release archives and Linux packages.
- Preserve least-privilege release permissions for Docker images, CLI artifacts, provenance, and Homebrew publication.

## [0.2.1] - 2026-09-12

### Security

- Refresh the application, frontend, native-build, and release-automation dependencies, including patched `js-yaml` and `league/commonmark` releases with no remaining Composer or npm audit advisories.
- Update pinned GitHub Actions used by documentation, native-proxy, and release workflows.

### Operations

- Build the native-proxy production image with Go 1.27.1 while retaining Go 1.26.6 in CI as a compatibility gate.
- Route all Dependabot updates to `develop` so dependency changes follow the verified promotion path rather than targeting `main` directly.

## [0.2.0] - 2026-09-11

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
- Require explicitly pinned, matching application and native-proxy release images in production Compose instead of silently falling back to a stale or moving tag.
- Add corruption, invalid-credential, partial-copy resume, source-mutation, cross-driver, and native-client regression coverage.
- Qualify matching `psql` 14–18 and MySQL CLI 8.0/8.4 executables in the native integration matrix, with metadata, prepared/binary protocol, read-only and read+write transactions, cancellation, reconnect, denial-recovery, and live-revocation coverage.

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

[Unreleased]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.10...HEAD
[0.2.10]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.9...v0.2.10
[0.2.9]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.8...v0.2.9
[0.2.8]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.7...v0.2.8
[0.2.7]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.6...v0.2.7
[0.2.6]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.5...v0.2.6
[0.2.5]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.4...v0.2.5
[0.2.4]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/adiwidia-dev/crucible-db/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/adiwidia-dev/crucible-db/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/adiwidia-dev/crucible-db/releases/tag/v0.1.0
