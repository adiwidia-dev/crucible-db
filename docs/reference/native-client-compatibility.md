# Native client compatibility

Native client access is a governed tunnel for PostgreSQL and MySQL clients. The local client connects only to the Crucible CLI on loopback; the CLI carries database-protocol bytes through the same `APP_URL` origin to the private native proxy. This page is the source of truth for client-support claims; other documentation must not imply a desktop client is supported before it appears as qualified here.

## Automated qualification matrix

The native CI workflow runs the qualification harness against fresh Compose targets. Each target uses a database credential that can work inside its assigned database but cannot create users or roles.

| Engine | Versions qualified in CI | Evidence |
| --- | --- | --- |
| PostgreSQL | 14, 15, 16, 17, 18 | Same-origin discovery, SCRAM authentication, cached and parameterized prepared statements, requested binary results, suspended-portal resume, policy denial, and live Redis revocation. |
| MySQL | 8.0, 8.4 | Same-origin discovery, native authentication, read execution, policy denial, and a clean disconnect before the PostgreSQL sequence. |

The race suite, bounded PostgreSQL/MySQL/tunnel fuzz smoke tests, connection-ceiling load test, and proxy image read-only/non-root smoke test run alongside the matrix.

These rows qualify the database engines and protocol implementation through the automated Go clients used by the integration harness. They do not automatically qualify every command-line, JDBC, or desktop client built on those protocols. Client qualification also covers the client's startup queries, metadata discovery, cancellation behavior, reconnect behavior, and exact version-specific defaults.

## Desktop tools and drivers

The server speaks PostgreSQL and MySQL protocol, but a desktop client is not labelled **supported** until the version has repeatable qualification evidence for the native tunnel. This avoids promising behavior based only on direct-to-database testing.

| Client | Status | Required evidence before support is claimed |
| --- | --- | --- |
| `psql` | Partial manual qualification | Connect, schema discovery, simple/extended queries, prepared statements, cancellation, expiry, reconnect. |
| MySQL CLI | Partial manual qualification | Connect, schema discovery, prepared statements, cancellation, expiry, reconnect. |
| PostgreSQL JDBC | Pending manual qualification | Driver metadata, prepared statements, cancellation, TLS-disabled loopback profile, reconnect. |
| MySQL JDBC | Pending manual qualification | Driver metadata, prepared statements, cancellation, TLS-disabled loopback profile, reconnect. |
| DBeaver, DataGrip, TablePlus, MySQL Workbench | Pending manual qualification | Corresponding driver evidence plus UI connection/profile capture with no real credentials. |

Do not infer support from a client merely connecting. Record the client version, operating system, CLI version, target engine/version, access mode, and result in the release evidence before updating this table.

`Partial manual qualification` does not mean that the client is disabled or known to be incompatible. It means that some real-client workflows succeeded but the complete checklist has not been recorded. `Pending manual qualification` means that the protocol may work, but there is no repeatable client-specific evidence yet; qualification can uncover startup statements, metadata queries, or protocol behavior that needs an implementation change.

Manual psql smoke evidence from August 27, 2026: `psql` 18.4 on macOS successfully connected through the Crucible CLI loopback tunnel to the disposable PostgreSQL target, listed schemas, ran read-only statements, rejected multi-statement/write attempts under a read-only native lease, reconnected cleanly after a rejected statement, sent a Ctrl+C cancellation request for `pg_sleep(10)`, and reused the same connection after cancellation. Full support remains pending until extended-query, prepared-statement, and expiry behavior are qualified.

Manual MySQL CLI smoke evidence from August 27, 2026: MySQL CLI 8.0.34 on macOS successfully connected through the Crucible CLI loopback tunnel to the disposable MySQL 8.4 target using `--ssl-mode=DISABLED --get-server-public-key`, returned only the leased database from `SHOW DATABASES`, listed tables, returned column/index/create-table metadata for a leased-database table, ran read-only statements, rejected cross-database metadata and DML under a read-only native lease, and reconnected cleanly after a rejected statement. Full support remains pending until prepared-statement, cancellation, and expiry behavior are qualified.

The automated PostgreSQL harness already exercises extended queries, cached and parameterized prepared statements, binary results, suspended portals, and live revocation through `pgx`; the remaining `psql` work verifies those behaviors through the actual `psql` executable and covers natural lease expiry. The automated MySQL harness currently proves connection, read execution, policy denial, and disconnect behavior through `go-mysql`; prepared statements, cancellation, and natural lease expiry still need end-to-end qualification through the actual MySQL CLI. JDBC and desktop-tool rows require their own qualification because their startup and metadata traffic can differ from both harness clients and command-line clients.

## Supported connection profile

1. Start the CLI from the approved Native Client session.
2. Point the database tool at `127.0.0.1` and the CLI’s printed local port.
3. Use the one-time temporary username and password shown by Crucible.
4. Disable database-protocol TLS **only for this loopback hop**.

The CLI’s authenticated tunnel and the separately configured upstream TLS protect the remote portions of the path. Do not configure the desktop client with a production database hostname, certificate, or private key. MySQL CLI 8.0 may also require `--get-server-public-key` when the temporary user authenticates with MySQL's default `caching_sha2_password` plugin over a local non-TLS loopback connection.

## Session-control behavior

Native client access intentionally permits safe transaction and session-control commands that ordinary PostgreSQL and MySQL clients emit while browsing or querying a database, including `BEGIN`, `COMMIT`, `ROLLBACK`, and savepoint control. This keeps the proxy close to normal database-client behavior.

Those commands do not bypass governance. The proxy still authorizes every executable SQL statement independently, records sanitized statement metadata, blocks data-changing statements in read-only leases, and rejects attempts to loosen read-only transaction state or change roles, users, security context, files, replication, or administrative state.

## Stable unsupported behavior

| Capability | Result |
| --- | --- |
| Direct internet connection to the proxy listener | Impossible by design: no `3306`, `5432`, or `8081` host port is published. |
| Non-loopback CLI listener | Rejected with `native client listener must use a loopback address`. |
| Local database TLS requirement | Not supported: the CLI profile uses loopback with local TLS disabled. |
| User-controlled atomic deployment batch | Not provided. Native client transaction control is session-scoped client behavior; Deployment Batch remains ordered single-statement execution, not a user-controlled transaction wrapper. |
| Database user/role creation | Not enabled by the qualification target principal; Crucible never creates target-database identities. |
