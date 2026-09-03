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

- Status: pending.
- Add an admin-only guided UI over the proven command/service workflow.
- Gate: authorization, confirmation, progress, failure recovery, and audit tests pass.

### Phase 8 — Production deployment support

- Status: pending.
- Update production Compose, entrypoints, health checks, examples, and operator guidance for all drivers.
- Gate: clean production-style installations boot with each driver and restart all long-running processes correctly.

### Phase 9 — Regression and fault testing

- Status: pending.
- Run the full application and native-proxy suites plus interruption, credential, corruption, and partial-copy scenarios.
- Gate: no known regression in query access, deployment batches, approvals, auditing, or native-client access.

### Phase 10 — Acceptance and release

- Status: pending.
- Complete operator acceptance, upgrade testing, release notes, and rollback rehearsal.
- Gate: release checklist is signed off with evidence for all earlier gates.

## Current implementation boundary

Phases 1–6 now support first-run provisioning and operator-driven migration through the CLI. Existing installations can move between SQLite, PostgreSQL, and MySQL with verified copying, maintenance fencing, restart-aware cutover, and reverse synchronization before rollback. The authenticated admin migration UI remains intentionally deferred to Phase 7, and production Compose/operator packaging remains deferred to Phase 8.
