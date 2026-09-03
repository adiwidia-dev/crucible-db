# Application Database Drivers Delivery Plan

This document is the cross-check ledger for adding SQLite, PostgreSQL, and MySQL as Crucible DB's own control-plane database. The application database is separate from the database targets governed by Crucible.

## Safety invariants

- Existing SQLite installations continue to boot without creating a bootstrap configuration file.
- Every Eloquent model uses one stable Laravel connection named `control`.
- Application-database credentials are encrypted at rest with `APP_KEY` and are never stored in the control database.
- A managed driver change is activated only after all long-running application processes restart.
- The native proxy continues to use its signed Laravel control API and Redis. Its Go implementation and target-database protocol behavior are outside this change.
- No source data is deleted until a later migration phase has verified a complete copy and an operator explicitly confirms cutover.

## Delivery phases

### Phase 0 — Safety baseline

- Status: complete on the branch base (`3f74167`).
- Record the current automated-test state and preserve a rollback commit.
- Gate: existing PHP, frontend, and native-proxy checks are recorded before driver work.

### Phase 1 — Schema portability

- Status: complete on `feature/app-database-drivers`.
- Make the complete migration chain portable across SQLite, PostgreSQL, and MySQL.
- Add isolated application-database matrix services for local/CI verification.
- Gate: passed. The complete migration chain runs on SQLite, PostgreSQL 17, and MySQL 8.4 using isolated control-database services. The matrix exposed and fixed forward foreign-key ordering plus MySQL identifier/key-length incompatibilities.

### Phase 2 — Encrypted bootstrap configuration

- Status: complete on `feature/app-database-drivers`.
- Add a dedicated application-database driver type and a stable `control` connection.
- Support `managed` and `environment` configuration modes.
- Store managed connection configuration in an atomic, owner-readable, `APP_KEY`-encrypted file under persistent storage.
- Preserve existing installations without a bootstrap file by using their current `DB_*` configuration.
- Gate: passed. Encryption round-trip, secret-at-rest, `0600` permissions, tamper rejection, invalid-driver rejection, mode precedence, config-cache secret exclusion, and backward compatibility are verified.

### Phase 3 — Initial setup database selection

- Status: complete on `feature/app-database-drivers`.
- Place application-database selection before first-owner creation.
- Validate connectivity, require a dedicated empty network database, run migrations on a temporary connection, and write configuration only after success.
- Require an application-process restart before a newly written configuration becomes active.
- Gate: passed. Setup routing, conditional validation, encrypted SQLite selection, restart enforcement, network provisioning, complete native-control schema verification, and nonempty-database rejection are tested. The full PHP suite passes with 314 tests and 2,295 assertions; five gated tests are skipped by design. The enabled PostgreSQL/MySQL provisioning matrix adds three passing tests and 23 assertions.

### Phase 4 — Cross-driver copy engine

- Status: complete on `feature/app-database-drivers`.
- Build a resumable, deterministic, table-ordered copy and verification engine.
- Gate: passed. All six SQLite/PostgreSQL/MySQL source-destination directions complete a forward copy and reverse synchronization with matching row counts and canonical hashes. The matrix verifies timestamps, JSON, nullable values, preserved IDs, post-copy sequence continuity, raw native-client credential ciphertext, and successful decryption with the unchanged `APP_KEY`.

### Phase 5 — Migration and native-client safety controls

- Status: complete on `feature/app-database-drivers`.
- Add maintenance fencing, queue draining, native-lease admission blocking, and active-session checks.
- Gate: passed. The maintenance fence rejects web and native-control requests and blocks new queued work. Migration waits for queued, delayed, and reserved work to drain, pauses configured queues, skips scheduled control work, and refuses active browser sessions, native leases, or native connections. Failure tests prove the source configuration remains active and the fence is released after a cleanly handled copy failure.

### Phase 6 — CLI cutover and rollback

- Status: complete on `feature/app-database-drivers`.
- Add inspect, plan, migrate, verify, activate, and rollback commands.
- Gate: passed. Six operator commands provide encrypted planning, resumable copy, independent verification, explicit activation, restart finalization, and data-preserving rollback. State transitions and failures are recorded outside the changing database, configuration writes are retryable, cutover remains fenced until app/Horizon/scheduler restart is confirmed, and neither source nor destination is deleted.

### Phase 7 — Authenticated admin migration UI

- Status: complete on `feature/app-database-drivers`.
- Add an admin-only guided UI over the proven command/service workflow.
- Gate: passed. Admin authorization, typed cutover and rollback confirmations, non-overlapping progress polling, encrypted operator events, connectivity errors, retry actions, and a fenced recovery route are covered. Normal and native-control traffic remain blocked while the migration console stays available.

### Phase 8 — Production deployment support

- Status: complete on `feature/app-database-drivers`.
- Update production Compose, entrypoints, health checks, examples, and operator guidance for all drivers.
- Gate: passed for automated configuration and driver provisioning. Production Compose validates with the base, `control-postgres`, and `control-mysql` profiles; neither database publishes a port. Startup retries the selected database, readiness verifies database and cache, the storage volume retains encrypted managed configuration, and the operator guide defines the Octane/Horizon/scheduler restart boundary.

### Phase 9 — Regression and fault testing

- Status: complete on `feature/app-database-drivers`.
- Run the full application and native-proxy suites plus interruption, credential, corruption, and partial-copy scenarios.
- Gate: passed. The full Laravel suite, enabled cross-driver matrix, native Laravel suite, Go native-proxy suite, type checks, linting, production build, and targeted corruption, invalid-credential, genuine partial-copy resume, and fenced-source mutation tests pass.

### Phase 10 — Acceptance and release

- Status: automated checks complete; operator acceptance pending.
- Complete operator acceptance, upgrade testing, release notes, and rollback rehearsal.
- Gate: release notes and automated upgrade, rollback, production configuration, and regression evidence are complete. The production image also starts successfully against PostgreSQL 17 and MySQL 8.4, with database/cache health plus Octane, Horizon, and scheduler verified in each run. The operator must perform and sign off the manual checklist below before release.

#### Manual operator acceptance remaining

- [ ] Back up a representative SQLite installation and record the restore location.
- [ ] Confirm there are no active browser sessions, native leases, native connections, or queued database jobs.
- [ ] In **Admin > Application Database**, migrate SQLite to PostgreSQL and confirm the UI remains available while normal and native-client admissions return maintenance responses.
- [ ] Restart every Laravel runtime process when prompted, verify `/health`, then finalize activation.
- [ ] Verify users, roles, connections, approvals, drafts, execution history, audit records, notification preferences, and encrypted target credentials are present.
- [ ] Create and complete one safe Deployment Batch, one browser Query Access session, and one PostgreSQL and MySQL native-client session.
- [ ] Prepare rollback, restart every Laravel runtime process, finalize it, and confirm records created after cutover were synchronized back to SQLite.
- [ ] Repeat the migration and rollback rehearsal with MySQL as the destination.
- [ ] Test a production-style container recreation while PostgreSQL is active and again while MySQL is active; confirm Octane, Horizon, scheduler, gateway, and native proxy are healthy.
- [ ] Restore the backup in an isolated environment and confirm the restored application starts.
- [ ] Record operator, timestamp, application revision, source/destination versions, evidence links, and final approval.

## Current implementation boundary

Phases 1–9 and the automated portion of Phase 10 are implemented. Existing installations can move among SQLite, PostgreSQL, and MySQL through the CLI or authenticated admin workflow with verified copying, maintenance fencing, restart-aware cutover, and reverse synchronization before rollback. Production packaging supports embedded SQLite, optional isolated PostgreSQL or MySQL profiles, and external database endpoints. Only the manual operator acceptance checklist remains before release sign-off.
