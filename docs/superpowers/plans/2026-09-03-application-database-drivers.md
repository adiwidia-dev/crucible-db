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

- Status: pending.
- Build a resumable, deterministic, table-ordered copy and verification engine.
- Gate: representative data, encrypted attributes, IDs, timestamps, JSON, and nullable values round-trip across every supported source/destination pair.

### Phase 5 — Migration and native-client safety controls

- Status: pending.
- Add maintenance fencing, queue draining, native-lease admission blocking, and active-session checks.
- Gate: migration cannot begin with unsafe native activity and failed attempts leave the active database unchanged.

### Phase 6 — CLI cutover and rollback

- Status: pending.
- Add inspect, plan, migrate, verify, activate, and rollback commands.
- Gate: cutover is explicit, restart-aware, auditable, and reversible without deleting the source.

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

Phases 1–3 provision only a fresh, empty application database during first-run setup. Moving an existing installation between drivers remains disabled until Phases 4–7 provide verified copying, fencing, cutover, and rollback.
