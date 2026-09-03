# Native Proxy Implementation Audit

**Date:** 2026-08-27

**Scope:** Native Client Access plan Tasks 1–31

**Baseline commit:** `8e43716 feat: persist native proxy control state`
**Source plans:**

- [`2026-08-25-native-client-access-design.md`](../specs/2026-08-25-native-client-access-design.md)
- [`2026-08-25-native-client-access.md`](../2026-08-25-native-client-access.md)

This document is the durable source of truth for implementation findings discovered before Native Client Access manual verification. Keep each finding and its status current as fixes land. Do not remove deferred findings from this document; mark them fixed only after the referenced verification passes.

## Status legend

- **Open:** confirmed defect or plan drift that still requires work.
- **In progress:** implementation is currently being corrected.
- **Fixed:** corrected and covered by the verification recorded under the finding.
- **Deferred:** consciously postponed with an explicit reason and release impact.

## Release blockers

### NP-AUD-001 — CLI omits required device `cli_version`

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** The first device-authorization request receives HTTP 422, so a real CLI cannot establish Native Client Access.
- **Evidence:** Laravel requires `cli_version` in `StartNativeProxyDeviceAuthorizationRequest`; `defaultDeviceInput()` does not populate it.
- **Required correction:** Send the build version from the CLI and test the exact serialized device request.

### NP-AUD-002 — HTTPS discovery URL is passed to a WebSocket-only dialer

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** Same-origin production discovery returns an `https://` tunnel endpoint, while the tunnel dialer accepts only `ws://` and `wss://`.
- **Evidence:** Gateway discovery derives the endpoint from `APP_URL`; the CLI passes it unchanged to `tunnel.Dial`.
- **Required correction:** Validate discovery as same-origin HTTP(S), convert HTTP to WS and HTTPS to WSS at the tunnel boundary, and test both mappings.

### NP-AUD-003 — Unknown `SHOW` commands bypass the exact allowlist

- **Severity:** Critical / security
- **Status:** Fixed
- **Impact:** Unsupported metadata and administrative disclosure statements may be authorized as ordinary reads, including MySQL `SHOW GRANTS`, `SHOW PROCESSLIST`, global variables/status, and PostgreSQL `SHOW ALL`.
- **Evidence:** Both session-command policies return no decision for unknown `SHOW`; the fallback `QueryGuard` classifies every `SHOW` as read access.
- **Required correction:** Treat every unrecognized `SHOW` form as an explicit denial and add negative policy tests.

### NP-AUD-004 — Failed upstream authentication leaks connection reservations

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** Repeated target/TLS/credential failures can exhaust a lease until scheduled cleanup runs.
- **Evidence:** Laravel reserves before returning upstream credentials; several Go failure paths return without closing the reserved connection. Expired reservations are counted until pruning.
- **Required correction:** Register cleanup immediately after admission, close on every post-admission failure, and exclude/fail expired reservations synchronously during admission.

### NP-AUD-005 — CI integration tests bypass the CLI, tunnel, proxy, and Laravel control plane

- **Severity:** Critical / release assurance
- **Status:** Fixed
- **Impact:** Contract failures in device authorization and tunnel discovery can ship while CI remains green.
- **Evidence:** Current PostgreSQL and MySQL integration tests connect directly to their target databases.
- **Required correction:** Add a real system path covering device authorization, WebSocket tunnel, protocol proxy, target database, statement authorization/audit, and revocation. Expand workflow path filters to all native-control-plane files.

### NP-AUD-006 — User/global admission limits are process-local rather than distributed and atomic

- **Severity:** Critical / security and availability
- **Status:** Fixed
- **Impact:** Multiple Go or Laravel replicas can exceed the configured per-user or global ceilings.
- **Evidence:** Laravel reserves only against the lease count. User/global limits are checked later in an in-memory Go registry.
- **Required correction:** Serialize global, per-user, and per-lease reservation decisions in the application database before releasing upstream credentials. Keep the Go registry as defense in depth.

### NP-AUD-021 — PostgreSQL protocol identifiers disagree across Go and Laravel

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** Go sends and expects `postgresql`, while Laravel validates and returns its internal `pgsql` enum value. Device authorization, tunnel authorization, and connection authorization cannot complete for PostgreSQL.
- **Required correction:** Keep `pgsql` as the database-driver persistence value, define `postgresql` as the native wire/API value, and map explicitly at every Laravel control boundary.

### NP-AUD-022 — Device-flow JSON is not compatible with the Go contract

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** Carbon may serialize `expires_in` as a floating-point number that Go cannot decode into an integer. Qualification fixtures also exposed the enforced minimum device-code entropy/length contract.
- **Required correction:** Cast duration fields to integer seconds at the API boundary and exercise the exact public JSON contract in the system test.

### NP-AUD-023 — Discovery percent-encodes the tunnel lease placeholder

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** The CLI cannot substitute the lease ULID, so Laravel receives the literal `{lease_id}` value and rejects every tunnel authorization.
- **Required correction:** Publish the literal `{lease_id}` discovery template, accept the legacy percent-encoded form in the CLI, and test both representations.

### NP-AUD-024 — Internal lifecycle routes omit model-binding middleware

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** Authentication, heartbeat, and disconnect requests receive an empty model instance, causing ownership checks to throw and return HTTP 500.
- **Required correction:** Apply Laravel route model substitution to the signed internal route group, fail closed for malformed ownership state, and test the signed HTTP lifecycle route.

### NP-AUD-025 — Qualification fixtures retain stale proxy connections

- **Severity:** Critical / release assurance
- **Status:** Fixed
- **Impact:** Re-running the real system suite collides with unique external connection identifiers and can produce false protocol failures.
- **Required correction:** Make the local/testing-only integration seeder repeatable by removing its prior connection rows before rotating device and token state.

### NP-AUD-026 — MySQL disconnect performs an unsafe double close

- **Severity:** Critical / availability
- **Status:** Fixed
- **Impact:** A normal MySQL client disconnect can panic and terminate the complete native proxy process, dropping PostgreSQL and other MySQL sessions.
- **Required correction:** Close the third-party protocol connection only while it remains open and verify a completed MySQL session does not terminate the proxy before the PostgreSQL qualification runs.

### NP-AUD-027 — Deferred SQLite transactions deadlock concurrent control-plane writers

- **Severity:** Critical / availability
- **Status:** Fixed
- **Impact:** A disconnect cleanup and another control request can both read before writing, then fail immediately while upgrading their deferred transactions despite the configured busy timeout. The default SQLite deployment can therefore return HTTP 500 under normal concurrent native-client activity.
- **Required correction:** Use SQLite `IMMEDIATE` transactions so writers acquire the lock before transactional reads, retain WAL and the bounded busy timeout, and run the MySQL-to-PostgreSQL system sequence without artificial sleeps.

### NP-AUD-028 — PostgreSQL SCRAM messages are decoded without authentication context

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** PostgreSQL reuses frontend message type `p` for several authentication responses. Without telling `pgproto3.Backend` that SCRAM is active, every standards-compliant SASL initial response is decoded as a password message and rejected before Laravel admission.
- **Required correction:** Set the backend authentication type before receiving both the SASL initial response and continuation response, then verify a real `pgx` client can authenticate through the complete tunnel.

### NP-AUD-029 — PostgreSQL clients omit the SCRAM username after startup

- **Severity:** Critical
- **Status:** Fixed
- **Impact:** PostgreSQL clients such as `pgx` send the authenticated user in the startup packet and an empty SCRAM identity, as PostgreSQL permits. Requiring the identity again rejects valid credentials before Laravel admission.
- **Required correction:** Validate the nonempty startup username, accept an empty SCRAM identity as referring to that user, still reject any different nonempty identity, and cover the PostgreSQL-style SCRAM conversation.

### NP-AUD-030 — PostgreSQL startup omits required string-safety status

- **Severity:** Critical / compatibility
- **Status:** Fixed
- **Impact:** Common clients refuse to execute simple-protocol queries with parameters unless the server declares `standard_conforming_strings=on`, so authentication succeeds but ordinary queries still fail.
- **Required correction:** Publish the standard parameter status before `ReadyForQuery` and verify the real client can execute through the supported simple-query path.

### NP-AUD-031 — PostgreSQL proxy labels binary upstream values as text

- **Severity:** Critical / data correctness
- **Status:** Fixed
- **Impact:** The proxy describes every result column as text but its upstream `pgx` connection can return binary raw values. Clients then parse binary integers and other values as text, producing errors or incorrect results.
- **Required correction:** Fetch upstream results in simple/text mode while the proxy advertises text columns, then verify typed values through a real client.

## Release-blocker remediation verification

| Findings | Verification evidence |
| --- | --- |
| NP-AUD-001, NP-AUD-002, NP-AUD-023 | CLI metadata, tunnel scheme conversion, literal and legacy endpoint-template expansion, and same-origin discovery are covered by the CLI, tunnel, and gateway Go tests. |
| NP-AUD-003 | PostgreSQL and MySQL statement-policy tests deny unrecognized `SHOW` forms. The real system suite also denies `SHOW ALL` and `SHOW GRANTS`. |
| NP-AUD-004, NP-AUD-006 | Laravel admission tests cover failed/expired reservations and distributed lease, user, and global ceilings. Go cleanup paths are covered by package tests and the system sequence. |
| NP-AUD-005 | The integration-tag suite now traverses public discovery, device token issuance, loopback client, WebSocket tunnel, signed Laravel control API, protocol proxy, real PostgreSQL/MySQL targets, statement authorization/completion, and Redis revocation. |
| NP-AUD-021, NP-AUD-022 | Laravel contract tests cover external `postgresql` mapping and integer device-flow JSON; both protocols complete the real system flow. |
| NP-AUD-024 | The signed Laravel lifecycle contract test resolves a real connection model and transitions a reservation to active; malformed ownership state fails closed. |
| NP-AUD-025 | The integration-seeder test executes the seeder twice and confirms stale fixture connections are removed. |
| NP-AUD-026, NP-AUD-027 | The MySQL test disconnects before PostgreSQL starts in the same SQLite/Octane stack without a proxy panic, lock-upgrade failure, or control HTTP 500. |
| NP-AUD-028, NP-AUD-029, NP-AUD-030, NP-AUD-031 | PostgreSQL SCRAM unit coverage and the real `pgx` system test verify authentication context, startup-username behavior, required parameter status, and text result decoding. |

Post-remediation verification on 2026-08-27:

- Native Laravel suite: **49 passed, 298 assertions**, including the external proxy-connection traffic contract.
- Native Go system suite: **4 passed under `-race`**, including both real database engines.
- `go test -race ./...`, `go vet ./...`, and `gofmt -l .`: passed.
- `npm run types:check` and `npm run lint:check`: passed.
- Isolated `php artisan wayfinder:generate --with-form --no-interaction`: passed.
- Production Compose validation with an explicit native image and control secret: passed.
- `.venv-docs/bin/mkdocs build --strict`: passed.
- `Dockerfile.native` built as `crucible-native:audit`; `Dockerfile.production` built as `crucible-db:audit`; the latter smoke-tested as **PHP 8.5.9**.

## High-risk findings

### NP-AUD-007 — PostgreSQL extended-query portal lifecycle is incomplete

- **Severity:** High
- **Status:** Fixed
- **Impact:** Paginated portals can retain rows backed by a canceled context; re-bind/re-parse and portal `Describe` behavior diverge from PostgreSQL protocol expectations.
- **Required correction:** Add live extended-protocol tests, preserve execution context through `PortalSuspended`, close replaced resources, and emit correct describe messages.
- **Verification:** `internal/postgres` tests cover format expansion and invalid format rejection. The real pgx qualification path uses cached prepared statements, typed binary parameter/result formats, a portal suspended twice with `Execute(MaxRows=1)`, final resume, and `Sync`; PostgreSQL 17 completed the complete same-origin tunnel path on 2026-08-27.

### NP-AUD-008 — Production proxy receives the complete Laravel environment

- **Severity:** High / security
- **Status:** Fixed
- **Impact:** A compromised proxy process can read unrelated `APP_KEY`, SMTP, SSO, and application secrets.
- **Required correction:** Pass only the documented proxy control, Redis, listener, limit, identity, and logging variables.
- **Verification:** Production Compose now declares the proxy's explicit minimum environment and has no `env_file`. `NativeProxyControlContractTest` asserts that `APP_KEY` and mail credentials are absent. `docker compose --env-file .env.production.example -f compose.production.yaml config --quiet` passed on 2026-08-27.

### NP-AUD-009 — Readiness, metrics, idle timeout, and drain behavior are overclaimed

- **Severity:** High
- **Status:** Fixed
- **Impact:** Orchestration can send traffic before Redis/control dependencies are usable; metrics report incomplete or zero data; database sessions do not enforce the documented idle timeout.
- **Required correction:** Make readiness dependency-aware, implement the documented bounded metrics, enforce database-session idle limits, and test bounded graceful drain.
- **Verification:** Readiness now requires signed Laravel health and Redis checks; the gateway fails closed while unready. Unit coverage verifies active-connection/runtime metrics, idle deadlines, clean draining, and forced drain. The proxy's Docker healthcheck uses `/readyz`; the clean qualification stack became healthy before both database protocol suites passed.

### NP-AUD-010 — Release verification checks artifact names rather than integrity

- **Severity:** High / supply chain
- **Status:** Fixed
- **Impact:** A release can publish with invalid checksums/signatures or without the expected package metadata.
- **Required correction:** Execute checksum and signature verification, validate Homebrew metadata, and gate release publication on full native/system/image/PHP workflows.
- **Verification:** The release workflow calls the reusable application and native gates before publish, validates GoReleaser configuration, verifies checksums and keyless Cosign identity/bundle before and after publishing, and validates archive contents, packages, SBOMs, and cask metadata. `actionlint` passed; GoReleaser v2.18.0 validated the configuration and generated a local six-platform snapshot whose checksums and artifact structure passed `verify-native-release.sh`. The signed verification executes in the GitHub tag workflow, where OIDC identity is available.

### NP-AUD-011 — SQL audit redaction can retain values

- **Severity:** High / privacy
- **Status:** Fixed
- **Impact:** PostgreSQL dollar-quoted literals, MySQL hex/bit literals, and some upstream errors may persist sensitive values.
- **Required correction:** Extend dialect-aware literal redaction and sanitize error persistence with adversarial tests.
- **Verification:** The normalization/redaction tests cover dollar-quoted, prefixed, hexadecimal, bit, numeric, and regular string literals. Laravel stores a generic failure message for native upstream execution errors. `NativeProxyStatementWorkflowTest` passed with adversarial sensitive values.

### NP-AUD-012 — PostgreSQL upstream configuration uses raw DSN interpolation

- **Severity:** High
- **Status:** Fixed
- **Impact:** Valid credentials containing whitespace, quotes, or backslashes can be parsed incorrectly. Startup database and replication parameters are not fully enforced.
- **Required correction:** Build a typed `pgx.ConnConfig`, enforce the approved database, and reject replication startup mode.
- **Verification:** PostgreSQL now builds a typed `pgx.ConnConfig` without inheriting `PGHOST`, `PGDATABASE`, or `PGOPTIONS`; tests preserve opaque credential values and reject alternate startup databases and replication modes. `go test -race ./...` passed on 2026-08-27.

## Additional findings

### NP-AUD-013 — Device authorization limits and response behavior are incomplete

- **Severity:** Medium / high
- **Status:** Fixed
- **Correction:** Per-lease pending authorization limits, signed device-flow rate limiters, and generic owner-facing responses are enforced. `slow_down` now stores both the increased polling interval and the last polling time, so later polls use the server-authoritative value.
- **Verification:** `NativeProxyDeviceAuthorizationTest` covers challenge limits, persisted slow-down behavior, owner isolation, and both endpoint throttles. The full native Laravel suite passed on 2026-08-27.

### NP-AUD-014 — Protocol and startup compatibility gaps remain

- **Severity:** Medium / high
- **Status:** Fixed
- **Correction:** PostgreSQL cancellation registration is synchronized, MySQL accepts binary parameter data and reports text-row counts correctly, and the tunnel requires and verifies the versioned WebSocket subprotocol before authorization.
- **Verification:** Targeted PostgreSQL, MySQL, and tunnel tests plus `go test -race ./...`, `go vet ./...`, and a clean `gofmt -l .` passed on 2026-08-27.

### NP-AUD-015 — Connection observability metadata is not populated

- **Severity:** Medium
- **Status:** Fixed
- **Correction:** Reservation records now retain CLI platform and upstream TLS-verification metadata; authenticated client metadata and byte counters are populated by dedicated signed control calls. Upstream credentials are intentionally kept out of ordinary admission responses. Traffic reports resolve the client-facing proxy connection ID, then verify the reporting proxy owns that record before incrementing counters.
- **Verification:** `NativeProxyConnectionAdmissionTest` and `NativeProxyControlContractTest` cover metadata, upstream material, and the signed traffic contract; the Go control-client test validates the static traffic endpoint and payload.

### NP-AUD-016 — Native Client UI has secret-visibility and authorization clarity gaps

- **Severity:** Medium
- **Status:** Fixed
- **Correction:** The session panel uses `document.visibilitychange`; only a manager receives the lease identifier required to construct a command; and the CLI prints its actual loopback address after authorization.
- **Verification:** `NativeProxySessionPageTest`, `npm run types:check`, and `npm run lint:check` passed on 2026-08-27.

### NP-AUD-017 — CLI behavior and documentation are inconsistent

- **Severity:** Medium
- **Status:** Fixed
- **Correction:** The CLI exposes `crucible version`, supports private CAs and a bounded authorization timeout, emits advisory update events, and uses documented usage, authorization, network, and runtime exit categories.
- **Verification:** CLI command/unit tests cover command output, CA loading, timeout/default behavior, update notices, and exit classification. [`reference/crucible-cli.md`](../../reference/crucible-cli.md) is aligned with the binary.

### NP-AUD-018 — Control API differs from the approved auth-material and idempotency model

- **Severity:** Medium
- **Status:** Fixed
- **Correction:** Admission reserves only the governed connection; a separate signed post-reservation endpoint returns upstream material to the private proxy. Exact repeated signed mutations replay their encrypted response, while a reused request ID with a different canonical payload is rejected and concurrent processing returns a conflict.
- **Verification:** Control-authentication and connection-admission feature tests cover replay, mismatch rejection, reservation, and material retrieval. Go retry tests prove the same replay identity is retained after an uncertain network error.

### NP-AUD-019 — Transient and stale connection cleanup is incomplete

- **Severity:** Medium
- **Status:** Fixed
- **Correction:** The every-minute native pruning command expires unused approvals and reservations, fails stale active connections and running statements, and removes expired or retained disposable state according to configured windows.
- **Verification:** `NativeProxyPruneStateTest` covers the complete lifecycle cleanup set; the scheduler registers the command at one-minute cadence.

### NP-AUD-020 — CI, documentation, architecture graph, and runtime versions drift

- **Severity:** Low / medium
- **Status:** Fixed
- **Correction:** Native source and documentation are included in CI path coverage; the local Graphify graph was refreshed (the generated graph is intentionally ignored); operations and compatibility documentation now distinguish automated engine qualification from pending desktop-client qualification; and the production image uses PHP 8.5. Production Compose requires an explicit, matching immutable native image rather than a stale default.
- **Verification:** Strict MkDocs, production Compose configuration with explicit native image/control-secret values, and both native and production image builds are recorded in the post-remediation verification below.

### NP-AUD-032 — MySQL session-command authorization and metadata discovery drift

- **Severity:** Medium / high
- **Status:** Fixed
- **Impact:** MySQL CLI schema discovery failed through the live native proxy even though policy validation allowed `SHOW DATABASES`. The execution workflow rejected allowed session commands because they do not have a governed SQL query type. MySQL table metadata also lacked a database-scoped allowlist, so common client discovery commands such as `SHOW TABLES` were blocked.
- **Correction:** Allowed native session commands are now authorized and audited as read activity. MySQL database discovery remains proxy-filtered to the leased database, while table, column, index, and create-table metadata commands are allowed only when scoped to the leased database or the current session database.
- **Verification:** `NativeProxyStatementPolicyTest` and `NativeProxyStatementWorkflowTest` cover session-command authorization, metadata allowlisting, and cross-database denial. Manual MySQL CLI 8.0.34 smoke testing on macOS passed same-origin tunnel connection, `SHOW DATABASES`, `SHOW TABLES`, `SHOW FULL COLUMNS`, `SHOW INDEX`, `SHOW CREATE TABLE`, table reads, cross-database metadata denial, DML denial, and reconnect-after-denial against the disposable MySQL 8.4 target on 2026-08-27.

### NP-AUD-033 — Native transaction-control documentation drift

- **Severity:** Low / medium
- **Status:** Fixed
- **Impact:** Compatibility documentation said transaction and savepoint control were blocked in governed native sessions, while the native proxy policies intentionally allow safe transaction/session commands to mimic normal PostgreSQL and MySQL clients.
- **Decision:** Keep safe transaction and session-control commands allowed in Native Client Access. They are session behavior, not a governance bypass: every executable statement remains independently authorized, read-only leases still block data-changing SQL, and role/security/file/administrative/procedural controls remain blocked.
- **Correction:** Native client compatibility, user-guide, and supported-SQL docs now distinguish browser/deployment submitted SQL from native client session-control commands.

## Baseline verification

The pre-fix audit completed these checks:

- Focused PHPUnit suite: **117 passed, 756 assertions**.
- `go test -race ./...`: passed.
- `go vet ./...` and `gofmt` check: passed.
- `npm run types:check`: passed.
- `npm run lint:check`: passed.
- `docker compose -f compose.production.yaml config --quiet`: passed.
- `.venv-docs/bin/mkdocs build --strict`: passed.
- `git diff --check`: passed.

These green checks do not supersede open audit findings; NP-AUD-005 documents the principal end-to-end coverage gap.
