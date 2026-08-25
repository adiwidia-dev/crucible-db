# Native Client Access Design

**Status:** Approved for implementation planning
**Date:** 2026-08-25
**Product name:** Native Client Access
**Backend workflow:** Query Access with `native_proxy` transport

## 1. Executive decision

Crucible DB will add **Native Client Access** as a third user-facing choice beside Deployment Batch and Query Access. It is not a third backend request family. It reuses the Query Access approval, access-level, duration, cancellation, renewal, notification, and session lifecycle while fixing the transport to `native_proxy`.

Native database clients connect only through the official open-source Crucible CLI. The CLI opens a loopback listener, authenticates the operator through Crucible's browser session, and carries PostgreSQL or MySQL bytes over an authenticated HTTPS WebSocket tunnel to a separate Go proxy service. The Go service terminates the database wire protocol, authenticates a short-lived synthetic database credential, asks Laravel to authorize every statement, connects upstream with the encrypted credential already stored on the selected `DatabaseConnection`, and reports outcomes back to Laravel.

The target database credential does not need `CREATE USER`, `CREATE ROLE`, or account-management privileges. Synthetic users exist only in Crucible's control plane and Go proxy. The configured upstream account remains the absolute privilege ceiling.

## 2. Goals

- Provide DBeaver, DataGrip, TablePlus, `psql`, MySQL CLI, MySQL Workbench, PostgreSQL JDBC, and MySQL JDBC access through approved, time-bounded Crucible sessions.
- Support PostgreSQL and MySQL as first-class protocols in the completed feature.
- Preserve Crucible's existing role priority, connection-group policy, direct connection override, independent read/write approval, maximum write-session duration, cancellation, notification, and audit behavior.
- Present three clearly separated request choices in `query-requests/create` while retaining two backend workflow families.
- Require one exact target connection for every Native Client Access request and proxy lease.
- Issue short-lived synthetic database credentials without creating accounts in the target database.
- Deliver a vendor-neutral tunnel through the official Crucible CLI over HTTPS/WebSocket port 443.
- Keep PostgreSQL 5432 and MySQL 3306 private by default.
- Enforce current policy before connection and before every executable statement.
- Revoke active sockets within five seconds after session expiry, cancellation, user disablement, role reduction, connection deactivation, credential rotation, or manual lease revocation.
- Audit statement templates and outcomes without storing bound parameter values or result rows.
- Ship signed cross-platform CLI binaries, checksums, packages, container health checks, metrics, operator documentation, user documentation, and a complete compatibility test matrix.

## 3. Non-goals

- No third-party connector/provider abstraction.
- No provider-specific tunnel commands or configuration generators.
- No SSH, VPN, private-network, or direct-public-database connection mode.
- No public exposure of the Go service's PostgreSQL or MySQL listener ports.
- No target-side ephemeral user creation.
- No arbitrary upstream host, port, or database selection by the CLI or database client.
- No mounting Crucible's SQLite volume in the Go service.
- No sharing Laravel's `APP_KEY` with the Go service.
- No storage of query result rows, prepared-statement parameter values, database passwords, tunnel bearer tokens, or generated synthetic passwords in logs.
- No query result export for native proxy statements because the result flows directly to the database client.
- No protocol features that bypass statement authorization: PostgreSQL replication and `COPY`, and MySQL replication, `LOAD DATA LOCAL`, compression, multi-statements, and `COM_CHANGE_USER` remain unsupported until a later separately designed feature.

## 4. Terminology

| Term                          | Meaning                                                                                          |
| ----------------------------- | ------------------------------------------------------------------------------------------------ |
| Native Client Access          | User-facing Query Access transport used by desktop and command-line database clients.            |
| Query Session                 | Existing approved, time-bounded Crucible access window.                                          |
| Proxy lease                   | One target-specific authorization and synthetic database credential attached to a Query Session. |
| Device authorization          | Browser-assisted CLI authentication modeled on RFC 8628 semantics.                               |
| Tunnel token                  | Short-lived bearer credential held only in CLI memory and accepted by the Go tunnel endpoint.    |
| Synthetic database credential | Temporary username and one-time password authenticated by the Go PostgreSQL/MySQL listener.      |
| Control API                   | Internal HMAC-authenticated Laravel JSON API used only by `crucible-proxy`.                      |
| Statement authorization       | Fail-closed Laravel decision made before a native statement is forwarded upstream.               |

## 5. User experience

### 5.1 Request creation

The create page presents three stable choices:

1. **Deployment Batch**: ordered, known SQL reviewed before execution.
2. **Query Access**: in-app editor during an approved access window.
3. **Native Client Access**: DBeaver, DataGrip, `psql`, `mysql`, and other native clients through the Crucible CLI.

Native Client Access asks for:

- one active connection with native proxy enabled;
- a **Session access level** choice using the same two-option control as Query Access: **Read-only** or **Read + write**;
- duration;
- reason/title and description.

Read + write is disabled when the selected connection's effective Native Client Access mode does not permit write. If the role's effective native mode is disabled, that connection is not selectable for Native Client Access. The selected access level is enforced for the complete proxy session and is shown unchanged to the reviewer.

It does not show an SQL editor, statement builder, batch preflight, schedule controls, or multiple-target selector. The selected transport and access level are immutable after submission; changing either requires a new request and approval.

### 5.2 Review

List and detail pages show a **Native client** transport label next to **Query Access**. Reviewers see target, driver, access level, duration, current role policy, approval requirement, and the fact that SQL is not known before approval. Approval uses the existing Query Access review engine.

### 5.3 Session activation

After approval, the requester starts the Query Session. The detail page then offers **Create temporary credentials**. Credential creation is explicit and audited.

The generated password is shown to the user exactly once. Crucible stores an Argon2id-compatible Laravel password hash plus a separately encrypted, hidden protocol-authentication secret. The encrypted copy is required because PostgreSQL SCRAM and MySQL challenge authentication cannot be served from an Argon2 verifier. It is available only to the HMAC-authenticated Go service during a bounded handshake, is never returned to the browser again, and is erased when the lease is revoked or expires. If the user loses it, **Rotate credentials** revokes the old lease credential, disconnects every active socket, creates a new password, and displays it once.

Plaintext credentials are returned only by a dedicated authenticated, CSRF-protected JSON endpoint with `Cache-Control: no-store`. They are held only in React component memory, excluded from Inertia remembered/history state, never placed in Laravel flash/session storage, and absent from repeat responses.

Exactly one proxy lease exists for a Native Client Query Session. The database enforces a unique `query_session_id`. The first credential-creation request accepts an idempotency key and returns the one-time password in that response. Repeating the same request after a successful response does not create another lease and returns `credentials_already_created`; because plaintext is never persisted, the user must choose **Rotate credentials** if the original response was lost. Rotation updates the existing lease and increments its credential version rather than creating a second lease.

The session page shows:

- driver and exact target;
- access level and expiry countdown;
- synthetic username;
- one-time password disclosure or rotation state;
- CLI installation links and checksum guidance;
- a copyable `crucible connect` command containing no reusable secret;
- local DBeaver/CLI connection fields;
- active proxy connections;
- recent statement outcomes;
- **Revoke credentials** and **End session** actions.

### 5.4 CLI flow

The generated command is:

```bash
crucible connect \
  --server https://tunnel.example.com \
  --lease 01K... \
  --listen 127.0.0.1:15432
```

For MySQL the recommended local port is `13306`. The CLI command never includes the synthetic database password, device code, tunnel token, upstream credential, or internal target address.

`--server` always names the Go service's public HTTPS origin. The Go service exposes a fixed discovery and CLI authorization surface on that origin:

- `GET /.well-known/crucible-native-client.json` returns protocol version, device authorization path, token path, tunnel path, verification URI on the Laravel web origin, and minimum/supported CLI versions;
- `POST /v1/device/authorize` validates size/rate limits and forwards the request through the HMAC-authenticated internal control API;
- `POST /v1/device/token` applies polling limits and forwards the exchange through the internal control API;
- `GET /v1/tunnel/{lease}` upgrades the authenticated database tunnel.

The CLI never needs to know Laravel's private control URL. The browser verification URI may use the normal `APP_URL`, including its existing outer browser access controls. The tunnel hostname itself needs only standard HTTPS reachability because Crucible device authorization and tunnel tokens are authoritative.

The CLI requests a device authorization, displays the verification URL and user code, opens the browser when possible, and polls at the server-provided interval. The authenticated browser page shows the same user code, target, protocol, access level, and expiry and requires explicit approval. The CLI receives a tunnel token only after the Query Session owner approves the matching device authorization.

The CLI then listens only on the requested loopback address. Each local TCP connection opens one WebSocket tunnel. DBeaver or another client connects to localhost with the synthetic database username/password and approved database name.

## 6. System architecture

```text
Browser / Inertia UI
        |
        v
Laravel control plane ---- SQLite (authoritative state)
        |       |
        |       +---- Redis revocation notifications and rate limits
        |
        +---- internal HMAC-authenticated control API
                         |
                         v
                  crucible-proxy (Go)
                  |     |       |
                  |     |       +-- HTTPS WebSocket tunnel :8081
                  |     +---------- MySQL listener 127.0.0.1:3306
                  +---------------- PostgreSQL listener 127.0.0.1:5432
                         ^
                         |
                    Crucible CLI
                         ^
                         |
              DBeaver / psql / mysql on localhost
```

The Go service is a distinct process and container. It is not an Octane request worker, Horizon job, scheduler task, or child process started by an HTTP request. Active TCP sockets are process-local data-plane state; authoritative leases and audits remain in Laravel's database.

The Go monorepo module lives under `native/` and produces two binaries:

- `crucible-proxy`: server-side tunnel and PostgreSQL/MySQL proxy service.
- `crucible`: end-user tunnel CLI.

Shared packages define tunnel frames, version negotiation, error codes, identifiers, redacted logging, and build metadata.

## 7. Trust boundaries and invariants

1. One proxy lease belongs to exactly one Query Session, user, connection, driver, access level, and expiry.
2. A Native Client Access request contains exactly one connection.
3. Client startup parameters cannot override the lease's upstream host, port, database, username, TLS policy, or protocol.
4. Laravel is authoritative for current user, role, connection, session, statement-family, and workspace policy.
5. The Go service fails closed when the control API is unreachable, signatures are invalid, policy is stale beyond five seconds, or an audit start record cannot be created.
6. The Go service never receives `APP_KEY` and never opens SQLite.
7. Upstream credentials are returned only by the authenticated internal control API, held only for the lifetime of the active upstream connection, never persisted by Go, and explicitly redacted from errors and logs.
8. Device codes and tunnel tokens are random, independently scoped, separately hashed, short-lived, and never interchangeable. Synthetic passwords are both hashed for ordinary verification and encrypted solely for database protocol authentication; their encrypted value is cleared at revocation/expiry.
9. Native listeners bind to loopback inside the Go container. Only the HTTPS tunnel endpoint is externally routable.
10. Ending or revoking a session closes the tunnel, protocol client socket, and upstream database socket.
11. Unknown protocol messages, unsupported capabilities, invalid state transitions, oversized packets, and unrecognized statement types are denied rather than forwarded opaquely.
12. Every forwarded executable statement has a Laravel-issued statement authorization identifier and a terminal outcome record.

## 8. Request and policy model

Add `AccessTransport`:

```text
browser
native_proxy
```

`QueryRequestKind` remains `single_execution` or `query_access`. Native Client Access persists:

```text
request_kind = query_access
access_transport = native_proxy
```

Add two gates:

- Connection: `native_proxy_enabled`.
- Direct/group role policy: `native_proxy_access_mode` with `none`, `read`, or `write`.

Administrators resolve Native Client Access as `write` but still use an auditable Query Access request/session. Direct connection policy continues to override group policy for the same role. Multiple group policies resolve the most restrictive native mode. Ordered roles continue to select the first applicable policy that grants the requested native query type.

`native_proxy_access_mode` is independent from `query_access_mode` and is capped by `access_mode`: maximum access `none` always resolves native mode `none`; maximum access `read` can resolve only `none` or `read`; maximum access `write` can resolve `none`, `read`, or `write`. Native mode never grants Deployment Batch or browser Query Access capability.

The role editor shows a separate **Native Client Access** dropdown beside **Query Access**. Its choices are **Disabled**, **Read-only**, and **Read + write**, with choices broader than Maximum access disabled. This explicit opt-in is required because native clients create a larger operational surface than the in-app editor.

Approval remains evaluated across selected targets, but Native Client Access always selects one target. Write Native Client Access uses the existing write approval and maximum write-session duration. Read Native Client Access uses the existing read approval rule.

## 9. Data model

### 9.1 Existing-table extensions

`query_requests`:

- `access_transport` enum-like string, non-null, default `browser`.

`database_connections`:

- `native_proxy_enabled` boolean, default false.
- normalized upstream TLS fields used by both browser execution and native proxy: `tls_mode`, `tls_ca_certificate`, `tls_client_certificate`, encrypted/hidden `tls_client_key`.

`role_database_permissions` and `role_connection_group_policies`:

- `native_proxy_access_mode` enum-like string, default `none`.

`query_session_queries`:

- `access_transport`, default `browser`;
- nullable `native_proxy_connection_id`;
- `sql_fingerprint`;
- `protocol_command`;
- nullable `parameter_metadata` containing count, format, and type identifiers only;
- nullable `command_tag` and `error_code`.

`query_executions`:

- nullable `native_proxy_connection_id`;
- `access_transport`, default `browser`;
- nullable `sql_fingerprint`, `parameter_metadata`, `protocol_command`, `command_tag`, and `error_code`.

### 9.2 New tables

`native_proxy_leases`:

- ULID primary key exposed to users;
- Query Session, Query Request, user, and database connection foreign keys;
- protocol and access mode;
- unique synthetic username;
- hashed synthetic password and encrypted/hidden protocol-authentication secret;
- credential version and one-time reveal timestamp;
- status: `pending_credentials`, `active`, `revoked`, `expired`;
- created, activated, expires, last-used, revoked timestamps;
- revocation reason and revoking user;
- maximum concurrent connections.
- unique `query_session_id`, which guarantees one lease for the one approved target.

`native_proxy_device_authorizations`:

- ULID;
- lease foreign key;
- hashed device code and hashed human-readable user code;
- CLI version, OS, architecture, optional sanitized device label;
- polling interval and poll counters;
- status: `pending`, `approved`, `denied`, `consumed`, `expired`;
- expiry, approved, denied, consumed timestamps and authorizing user.

`native_proxy_tokens`:

- ULID;
- lease and device authorization foreign keys;
- SHA-256 token hash because tokens are high-entropy random values;
- scope fixed to `native_tunnel`;
- issued, expires, last-used, and revoked timestamps;
- one active token per device authorization.

One lease may have up to three simultaneously active device authorizations/tunnel tokens for the same session owner, subject to the stricter lease and user connection limits. Every token remains independently attributable to its CLI device metadata. Lease expiry, credential rotation, session end, or manual revocation invalidates all tokens together.

`native_proxy_auth_attempts`:

- ULID;
- lease, tunnel token, and device authorization foreign keys;
- proxy instance ID, protocol, credential version, and Go-generated connection ID;
- status: `pending`, `consumed`, `expired`;
- created, expires, and consumed timestamps;
- unique connection ID and an index on pending expiry.

An authentication attempt exists only to bridge challenge-response protocol authentication into atomic connection admission. It contains no plaintext password or upstream credential and expires after 15 seconds.

`native_proxy_connections`:

- ULID generated by the Go service and accepted once by Laravel;
- lease, Query Session, Query Request, user, and database connection foreign keys;
- protocol, proxy instance ID, client application/version, CLI version/OS/architecture;
- upstream TLS mode and verified status without certificate secrets;
- connected, authenticated, last-activity, disconnected timestamps;
- disconnect reason and byte/statement counters.

All new foreign keys are indexed. Active-lease, expiry, username, token-hash, user-code-hash, connection-state, and statement-history lookup paths receive explicit indexes.

## 10. Device authorization and tunnel authentication

The flow uses RFC 8628 response/error semantics without turning Crucible into a general OAuth authorization server.

1. CLI posts `lease_id`, CLI metadata, and client nonce to the public device-authorization endpoint.
2. Laravel verifies that the lease exists, is active, belongs to a live Native Client Query Session, and can still be used under current policy.
3. Laravel returns `device_code`, `user_code`, `verification_uri`, `verification_uri_complete`, `expires_in=300`, and `interval=5` with `Cache-Control: no-store`.
4. CLI displays the code and attempts to open `verification_uri_complete`.
5. Fortify's existing browser session authenticates the user. The confirmation page requires the Query Session owner and displays target/access details plus the code for anti-phishing confirmation.
6. Approve and deny actions are rate limited, CSRF protected, and audited.
7. CLI polls the token endpoint no faster than the returned interval. Responses use `authorization_pending`, `slow_down`, `access_denied`, and `expired_token` semantics.
8. Successful exchange atomically marks the device authorization consumed and returns one high-entropy tunnel token expiring no later than the lease.
9. CLI holds the tunnel token only in memory and sends it in the WebSocket `Authorization: Bearer` header. It is never written to command history, configuration, environment, or disk.

Device/user codes and synthetic credentials are separately rate limited by IP, lease, username, and device authorization. Error responses do not reveal whether a username, lease, or individual secret was valid.

The Go service requests synthetic authentication material only after receiving a syntactically valid startup username. Its internal request includes the lease already authenticated by the WebSocket bearer token and `/v1/tunnel/{lease}` URL, the tunnel token/device authorization identity, proxy instance, protocol, and Go-generated connection ID. Laravel rejects any username that does not resolve to that exact lease. Laravel returns the material with a single-use authentication attempt ID bound to all of those identities, the current credential version, and a 15-second expiry. The response is marked `Cache-Control: no-store`. Go retains the decrypted secret only for the handshake, overwrites/discards the byte buffer immediately afterward, and never places it in any intermediary or application cache. After successful protocol authentication, Go submits the attempt ID rather than a password. Successful protocol authentication is not sufficient to reach the target: the separate atomic connection authorization call must consume the attempt, reserve capacity, and return upstream configuration.

## 11. Internal control API

The Laravel control API is mounted under `/internal/native-proxy/v1` and is unreachable from the public reverse proxy. Each request carries:

- proxy instance ID;
- timestamp within 30 seconds;
- unique request ID/nonce retained in Redis for 60 seconds;
- HMAC-SHA256 signature over method, canonical path, timestamp, request ID, and SHA-256 body hash using `NATIVE_PROXY_CONTROL_SECRET`.

Endpoints:

- `GET /discovery`: return the public CLI discovery document assembled from validated configuration.
- `POST /device-authorizations`: create the device/user codes for a Go-forwarded public request.
- `POST /device-token`: apply RFC 8628 polling semantics and atomically issue a tunnel token.
- `POST /tunnels/authorize`: before WebSocket upgrade, hash and validate the bearer token, bind it to the URL lease/device authorization/protocol/proxy instance/connection ID, recheck current user/session/connection/role policy and token expiry/revocation, and return a short-lived tunnel context. No upgrade occurs before this succeeds.
- `POST /leases/auth-material`: resolve a synthetic username only inside the lease already authenticated by the tunnel token and create one 15-second authentication attempt bound to lease, tunnel token/device authorization, proxy instance, credential version, protocol, and connection ID. Cross-lease usernames are rejected with the same generic authentication response. The response is `Cache-Control: no-store`, never contains target host, upstream username/password, or policy authorization, and is rate limited without username-enumeration detail.
- `POST /connections/authorize`: in one database transaction, atomically consume the single-use authentication attempt, recheck lease state/current user/role/connection policy, enforce lease/user/global limits, create a 15-second connection reservation, and then return target configuration plus decrypted upstream credentials. No upstream credential is released unless a slot has already been reserved.
- `POST /connections/{connection}/authenticated`: consume the reservation after upstream authentication and mark the connection active. An unconsumed reservation expires after 15 seconds and is excluded from future connection counts after cleanup.
- `POST /statements/validate-template`: validate a prepared statement template without creating a running execution record; blocked templates create a blocked audit event.
- `POST /statements/authorize-execution`: recheck current lease/policy and create the running `QuerySessionQuery`/`QueryExecution` only when execution is about to be forwarded.
- `POST /statements/{statement}/complete`: persist success/failure, duration, row count/affected rows, command tag, redacted error code/message, and audit event idempotently.
- `POST /connections/{connection}/heartbeat`: re-evaluate current session/user/role/direct/group/connection/workspace policy, update activity, and return continue/revoke within the two-second polling contract.
- `POST /connections/{connection}/close`: idempotently record closure.
- `GET /health`: control-plane readiness used only from the internal network.

Every mutating endpoint is idempotent by request ID. Authentication and connection registration use database transactions and locks to enforce connection limits. The proxy performs a heartbeat at most every two seconds while a socket is active. A missed heartbeat or control API failure closes the socket before the five-second revocation objective is exceeded.

## 12. Tunnel protocol

The public Go endpoint is `GET /v1/tunnel/{lease}` over HTTPS WebSocket and negotiates subprotocol `crucible.tunnel.v1`.

The CLI presents the bearer token, CLI metadata headers, requested protocol, and a random connection ID. The server validates the token through Laravel before upgrading. Origin-based browser access is rejected; only bearer-authenticated non-browser clients are allowed.

After upgrade, binary frames carry raw database protocol bytes. One WebSocket corresponds to one local TCP client connection. Text frames are not accepted. Compression is disabled to avoid memory-amplification and secret-compression risks. Frame/message limits, idle timeout, ping/pong, total byte limits, backpressure, half-close behavior, and close codes are explicit and tested.

The CLI accepts multiple sequential or concurrent local connections within the lease connection limit by opening one WebSocket per local socket. It binds only to IPv4 or IPv6 loopback and has no non-loopback override.

The local PostgreSQL/MySQL hop does not negotiate database-protocol TLS: PostgreSQL SSLRequest receives `N`, and MySQL does not advertise `CLIENT_SSL`. This is safe only because the socket is loopback-only and every byte leaving the device is inside the authenticated TLS WebSocket. Generated DBeaver/JDBC/CLI instructions explicitly disable database-protocol TLS for localhost. Go-to-target upstream TLS remains independent and follows the configured connection TLS policy.

## 13. PostgreSQL proxy engine

Use `github.com/jackc/pgx/v5/pgproto3` from pgx `v5.10.0` or the security-patched successor pinned in `native/go.mod` at implementation time. Do not use archived `github.com/jackc/pgproto3/v2`.

Required behavior:

- PostgreSQL protocol 3.0 and maintained pgx-supported 3.2 negotiation.
- SSLRequest handling that returns `N`; database-protocol TLS is not advertised on the loopback-only local hop because the CLI tunnel provides transport TLS.
- Synthetic credential authentication using SCRAM-SHA-256 with constant-time failure behavior.
- Exact database-name enforcement from the lease.
- Capture sanitized `application_name` for audit correlation.
- Upstream connection with lease-fixed target and stored credentials, preserving configured TLS verification.
- Simple Query protocol.
- Extended Parse, Bind, Describe, Execute, Close, Flush, and Sync with prepared-statement and portal state tracked per connection.
- Statement authorization at Parse for template validity and again at Execute for current lease/policy.
- Bound parameter values forwarded but never sent to Laravel or logs; audit stores parameter OIDs, formats, count, and template fingerprint only.
- Backend response forwarding, transaction status tracking, affected-row/command-tag extraction, errors, notices, and ready state.
- CancelRequest mapping using proxy-generated client keys mapped to the real upstream cancellation mechanism.
- Graceful termination and rollback-by-upstream-disconnect for incomplete transactions.
- Explicit rejection of replication startup, `COPY` messages/statements, function-call protocol, unsupported protocol versions, and oversized messages.

## 14. MySQL proxy engine

Use `github.com/go-mysql-org/go-mysql` `v1.16.0` or its security-patched successor pinned in `native/go.mod` at implementation time. Build on its `server` and `client` packages; do not write authentication packet cryptography from scratch.

Required behavior:

- MySQL 8.4-compatible initial handshake and capability negotiation.
- Synthetic credentials using `caching_sha2_password` challenge/RSA behavior inside the TLS tunnel; the loopback MySQL server does not advertise `CLIENT_SSL`.
- Exact database-name enforcement from the lease.
- Capture sanitized connection attributes and client name/version.
- Upstream connection using fixed target configuration and TLS verification.
- `COM_INIT_DB` restricted to the lease database.
- `COM_QUERY` with one-statement enforcement.
- `COM_STMT_PREPARE`, `COM_STMT_EXECUTE`, `COM_STMT_RESET`, and `COM_STMT_CLOSE` with template authorization and parameter-value redaction.
- `COM_PING`, `COM_QUIT`, transaction/autocommit behavior, result metadata, warnings, command status, and affected rows.
- Explicit capability removal/rejection for compression, multi-statements, local files, replication/binlog commands, `COM_CHANGE_USER`, and unsupported commands.
- Graceful disconnect and rollback of incomplete transactions.

## 15. Native statement policy

Create `ProxyStatementPolicy` in Laravel. It delegates executable SQL family recognition to shared `QueryGuard` primitives but has a separate allowlist for native-client session commands. Existing Deployment Batch and browser Query Access restrictions remain unchanged.

Allowed protocol/session behavior is parsed against an engine-specific allowlist. String-prefix matching is not sufficient, and unknown clauses or values are denied.

PostgreSQL allowlist:

- `BEGIN` or `START TRANSACTION` with optional `WORK`/`TRANSACTION`, `ISOLATION LEVEL READ COMMITTED|REPEATABLE READ|SERIALIZABLE`, and an access mode no broader than the lease;
- `COMMIT`/`END`, `ROLLBACK`/`ABORT`, `SAVEPOINT <identifier>`, `RELEASE SAVEPOINT <identifier>`, and `ROLLBACK TO [SAVEPOINT] <identifier>`;
- `SET [SESSION]` or `SET LOCAL` only for `application_name`, `client_encoding` fixed to UTF8, `DateStyle`, `TimeZone`, `extra_float_digits`, `bytea_output`, `standard_conforming_strings`, `search_path`, and configured timeout variables whose numeric value does not exceed the server maximum;
- `SHOW` and `RESET` only for those same variables; `RESET ALL` is denied;
- catalog and information-schema reads that pass normal read-family policy;
- protocol Parse/Bind/Describe/Execute/Close/Flush/Sync lifecycle.

`SET ROLE`, `SET SESSION AUTHORIZATION`, `ALTER ROLE`, `DISCARD ALL`, `LISTEN`, `NOTIFY`, SQL `PREPARE`/`EXECUTE`, and any attempt to set `default_transaction_read_only=off` are denied. `search_path` values are identifier lists only; special command injection and function calls are denied.

MySQL allowlist:

- `START TRANSACTION`/`BEGIN` with an access mode no broader than the lease, `COMMIT`, `ROLLBACK`, `SAVEPOINT <identifier>`, `RELEASE SAVEPOINT <identifier>`, and `ROLLBACK TO [SAVEPOINT] <identifier>`;
- `SET [SESSION]` only for `autocommit`, `transaction_isolation`, `transaction_read_only`, `sql_mode`, `time_zone`, `character_set_results`, and bounded `net_read_timeout`, `net_write_timeout`, and `wait_timeout` values;
- `SET NAMES utf8mb4` with an approved collation;
- `USE <database>` only when the identifier exactly matches the lease database;
- `SHOW DATABASES`/`SHOW SCHEMAS`, with the proxy filtering the response to the lease database and required system schemas;
- `SHOW TABLES`, `SHOW FULL TABLES`, `SHOW COLUMNS`, `SHOW FULL COLUMNS`, `SHOW INDEX`, `SHOW CREATE TABLE`, `SHOW CREATE VIEW`, and `SHOW TABLE STATUS`, optionally qualified only by the exact lease database;
- `SHOW CREATE DATABASE` only for the exact lease database;
- `SHOW VARIABLES` only for the explicit session-variable allowlist, plus `SHOW STATUS` with no global scope;
- `SHOW WARNINGS`, `SHOW ERRORS`, `SHOW COUNT(*) WARNINGS`, `SHOW COUNT(*) ERRORS`, `SHOW CHARACTER SET`, `SHOW COLLATION`, and `SHOW ENGINES`;
- `DESCRIBE` and catalog/information-schema reads that pass normal read-family policy;
- protocol prepared-statement prepare/execute/reset/close lifecycle.

All other `SHOW` forms are denied, including grants, users, privileges, process lists, binary/relay logs, replication source/replica status, procedure/function status, events, triggers, and global status/variables. Global or persistent variable writes, `SET ROLE`, `SET PASSWORD`, `LOCK TABLES`, `UNLOCK TABLES`, `FLUSH`, `RESET`, `KILL`, user variables containing executable SQL, and any attempt to set `transaction_read_only=OFF` on a read-only lease are denied.

Blocked behavior includes:

- changing the database, user, role, authorization identity, search target outside the approved database, or proxy-enforced read-only state;
- administrative, security-management, file-access, procedural, replication, bulk-copy, and unsupported statement families;
- multi-statement strings;
- attempts to disable statement timeout, read-only protection, auditing, or connection identity;
- any statement disabled by workspace SQL-family policy.

Read-only defense in depth:

1. Laravel authorizes only read/session-control operations.
2. PostgreSQL upstream sessions set `default_transaction_read_only=on` and enforce read-only transactions.
3. MySQL upstream sessions set `SESSION transaction_read_only=ON`.
4. Policy blocks attempts to turn read-only off.
5. Stored upstream account privileges remain the final ceiling.

Read + write sessions allow only workspace-enabled governed statement families plus safe transaction/session commands. Emergency SQL fallback never applies.

## 16. Audit and privacy

Audit events cover:

- Native Client request creation/review/approval;
- Query Session start/end/expiry;
- lease creation, credential reveal, rotation, revocation, and expiry;
- device authorization creation, approval, denial, expiry, and token issue;
- proxy connection open/authenticate/heartbeat failure/close;
- statement authorized, blocked, succeeded, and failed;
- policy-change, user-disable, connection-disable, and operator-shutdown revocations.

Allowed prepared templates are validated without creating execution records. A running statement record is created only immediately before an Execute/COM_STMT_EXECUTE or simple query is forwarded. Statement records contain normalized SQL template, fingerprint, statement family/query type, protocol command, parameter count/types/formats, timing, status, affected/returned row count when available, command tag, and redacted error code/message. They never contain bound values or result rows.

Logging uses structured fields and a central redaction helper. SQL text is recorded only in the application audit tables according to existing Crucible behavior, not emitted to container logs. Metrics use bounded labels and never include user id, email, lease id, database name, SQL fingerprint, or connection id.

## 17. Revocation, expiry, and lifecycle

- Lease expiry is never later than Query Session expiry.
- No new statement is accepted at or after expiry.
- Active sockets close within five seconds of expiry or revocation.
- Closing the upstream socket rolls back uncommitted transactions.
- A statement already accepted may finish only while the lease remains valid; revocation cancels/tears down the upstream operation.
- If a commit was forwarded before revocation, its real outcome is recorded; Crucible never reports rollback without evidence.
- Credential rotation increments the lease credential version, invalidates tunnel tokens, and closes every connection before revealing the new password.
- Session renewal follows the existing Query Access retry/renewal process and creates a new Query Session and lease; it does not silently extend an existing credential.
- Process shutdown stops accepting tunnels, sends a service-restart close reason, allows a bounded drain period, then cancels remaining upstream connections.

## 18. Error handling

Public errors use stable codes and safe user copy. They do not reveal whether a lease, username, password, target, or policy component was specifically valid.

CLI exit categories distinguish configuration, authorization pending/denied/expired, tunnel TLS, local bind, proxy unavailable, lease revoked/expired, and protocol disconnect. `--json` emits machine-readable status without secrets.

The Go service wraps internal failures with request/connection correlation ids, logs redacted root causes, and returns generic protocol-appropriate authentication or execution errors. Laravel internal endpoints return stable JSON codes and never exception traces.

## 19. Resource controls

Defaults are configuration-backed and documented:

- maximum 3 active database connections per lease;
- maximum 10 active native connections per user;
- 15-minute idle timeout;
- absolute timeout equal to lease expiry;
- 5-second control heartbeat/revocation ceiling with a 2-second heartbeat interval;
- 10 MiB maximum individual protocol message/frame;
- 32 MiB bounded buffers per connection including queued writes;
- 256 prepared statements and 256 portals per connection;
- 30-second upstream connect timeout;
- configurable statement timeout bounded by remaining lease duration;
- rate limits for device authorization, token polling, synthetic authentication, and control API calls;
- global connection ceiling and readiness failure before memory exhaustion.

## 20. Deployment

`compose.yaml` gains a development `native-proxy` service and keeps target PostgreSQL/MySQL fixtures. `compose.production.yaml` gains a `native-proxy` service using the same immutable release version as the app. It receives only:

- internal Laravel control URL;
- dedicated HMAC control secret;
- Redis address for revocation subscription;
- tunnel/listener/limit configuration;
- proxy instance id;
- log level.

It does not receive `APP_KEY`, Laravel database path, mail/SSO secrets, or target credentials as environment variables.

Production publishes the tunnel service to loopback on a configurable host port, default `127.0.0.1:8081`, for an operator-managed TLS reverse proxy at `NATIVE_PROXY_PUBLIC_URL`. The PostgreSQL/MySQL listeners are not published. The public tunnel URL should use a dedicated hostname when the main web hostname is protected by an outer access product that cannot pass the CLI bearer token; Crucible itself remains the tunnel authority.

Health endpoints:

- `/healthz`: process alive;
- `/readyz`: listeners active, Redis reachable, Laravel control API authenticated;
- `/metrics`: internal Prometheus format.

Backups require no Go-local state. Laravel's existing storage backup contains leases/audits. Redis loss causes fail-closed connection shutdown and is recoverable from Laravel state.

Scheduled pruning removes expired device authorizations, tunnel-token hashes, consumed/expired authentication attempts, and abandoned reservations after 30 days. Connection and statement audit records follow the workspace audit-retention policy and are never pruned by the transient-state command.

## 21. CLI distribution

Release artifacts:

- macOS amd64/arm64 archives and Homebrew formula;
- Linux amd64/arm64 archives, `.deb`, and `.rpm`;
- Windows amd64/arm64 zip archives;
- SHA-256 checksum file;
- signed checksums and provenance/SBOM artifacts.

The CLI includes `connect`, `version`, and shell completion. `connect` supports `--server`, `--lease`, `--listen`, `--no-browser`, `--json`, `--ca-file`, and bounded timeout flags. It honors standard proxy environment variables for outbound HTTPS but never reads a database password from an argument. It has automatic update notification but no automatic self-update.

## 22. Observability

Structured logs and metrics include:

- active/pending tunnels and protocol connections;
- authentication success/failure totals by protocol and bounded reason;
- authorization latency and failure totals;
- upstream connect latency/error totals by driver and bounded category;
- statement totals and latency by driver, query type, family, and outcome;
- revocation propagation time;
- bytes transferred;
- rejected oversized/unsupported protocol messages;
- Go runtime/process metrics.

Operator health pages in Crucible show proxy readiness, version, instance id, active connection counts, last control heartbeat, and mismatch warnings between app/CLI/proxy protocol versions.

## 23. Accessibility and interface quality

- WCAG 2.2 AA, visible focus, keyboard operation, reduced motion, text labels in addition to status colors.
- Request-type controls remain spatially stable and usable at 200 percent zoom.
- Credentials use explicit reveal/copy actions and never disappear without a clear confirmation step.
- Copy success is announced through accessible status text.
- CLI commands and identifiers use monospace; explanatory prose does not.
- Mobile layouts preserve request/review/expiry/revocation state but do not pretend that a desktop database client runs on mobile.
- No nested cards, decorative gradients, provider branding, or hidden approval consequences.

## 24. Testing strategy

Implementation follows test-first red-green-refactor cycles.

### Laravel/PHP

- Feature tests for request validation, one-target invariant, immutable transport, role/group/direct policy resolution, approval, lifecycle, credential rotation, device authorization, internal HMAC/replay protection, statement authorization, audit privacy, revocation, and authorization failures.
- Unit tests for token hashing, code formatting, HMAC canonicalization, proxy statement policy, state transitions, and redaction.
- Migration tests/backfill assertions for browser transport defaults.

### Go

- Unit tests for tunnel framing, CLI commands, browser/device flow, token redaction, HMAC client, limits, state machines, protocol message parsing, prepared statement tracking, and graceful shutdown.
- Go fuzz tests for public tunnel frames and PostgreSQL/MySQL decoders/state transitions.
- Race detector and leak-sensitive shutdown tests.
- Integration tests against PostgreSQL 14 through 18 and MySQL 8.0/8.4 fixtures where supported by CI resources.

### End to end

- Laravel + Redis + Go proxy + real PostgreSQL/MySQL.
- Read-only enforcement and write approval.
- Prepared statements with sensitive values proven absent from Laravel/Go logs and audit metadata.
- DBeaver, DataGrip, TablePlus, PostgreSQL/MySQL CLI, JDBC smoke scripts or documented manual release matrix where clients cannot be automated in CI.
- Generic TLS reverse-proxy coverage for WebSocket `Authorization` forwarding, disabled WebSocket compression, long-lived idle/active connections, close-code propagation, request size limits, and graceful drain; no provider-specific integration is introduced.
- Expiry, manual revoke, user disable, role reduction, connection deactivate, Redis outage, Laravel outage, proxy restart, target restart, network interruption, and tunnel reconnect.
- Load tests for configured connection ceilings, backpressure, long-running queries, idle clients, and graceful drain.

### Supply chain and release

- PHPStan, Pint, PHPUnit, ESLint, Prettier, TypeScript, strict MkDocs.
- `go test -race ./...`, fuzz smoke budget, `go vet`, staticcheck, govulncheck, Go formatting, and module verification.
- Composer/npm/Go dependency audit.
- Multi-stage production image build, non-root proxy container, SBOM, vulnerability scan, OCI labels, and clean-container smoke test.
- Signed CLI artifacts and checksum verification on each supported OS/architecture.

## 25. Documentation deliverables

- User guide: requesting, reviewing, activating, installing CLI, DBeaver/CLI setup, rotating credentials, ending access, and troubleshooting.
- Administrator guide: enabling connection/role permissions, tunnel URL, limits, upstream TLS, and audit review.
- Operator guide: DNS/TLS reverse proxy, Compose deployment, health/metrics, upgrades, draining, incident response, and log redaction.
- Security reference: trust boundaries, credential lifecycle, read-only layers, blocked capabilities, privacy, and threat model.
- Protocol compatibility matrix and known limitations.
- CLI reference with every flag, exit code, JSON schema, checksum/signature verification, and examples.
- Architecture decision record for the Go data plane and Query Access reuse.
- Release notes and changelog updates.

## 26. Acceptance criteria

The feature is complete only when all of the following are true:

1. The UI presents exactly Deployment Batch, Query Access, and Native Client Access.
2. Native Client Access persists as Query Access with `native_proxy` transport and exactly one target.
3. The connection must enable native proxy use, and the effective role `native_proxy_access_mode` must allow the request's selected Session access level.
4. PostgreSQL and MySQL temporary credentials work through `crucible connect` with supported clients.
5. The target account requires no user/role creation privilege.
6. Read-only and read + write modes enforce current Crucible policy before every statement.
7. Prepared statement parameter values and result rows are absent from stored proxy audit data and logs.
8. Expiry and every revocation trigger close active connections within five seconds.
9. Unsupported protocol capabilities fail explicitly and cannot bypass auditing.
10. Go proxy/CLI and Laravel pass their complete unit, feature, integration, fuzz, race, static analysis, dependency audit, image, and end-to-end gates.
11. Production exposes only the HTTPS tunnel endpoint; native database listeners remain private.
12. Signed CLI artifacts and complete user/admin/operator/security documentation are published.
