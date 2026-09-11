# Native client compatibility

Native client access is a governed tunnel for PostgreSQL and MySQL clients. The local client connects only to the Crucible CLI on loopback; the CLI carries database-protocol bytes through the same `APP_URL` origin to the private native proxy. This page is the source of truth for client-support claims; other documentation must not imply a desktop client is supported before it appears as qualified here.

## Automated qualification matrix

The native CI workflow runs the qualification harness against fresh Compose targets. Each target uses a database credential that can work inside its assigned database but cannot create users or roles.

| Engine | Versions qualified in CI | Evidence |
| --- | --- | --- |
| PostgreSQL | 14, 15, 16, 17, 18 | Same-origin discovery, SCRAM authentication, actual `psql`, connection identity, schema discovery, simple and extended queries, named and cached prepared statements, binary results, suspended portals, read-only transactions and savepoints, transactional `INSERT`/`UPDATE`/`DELETE` through a write lease, denial and upstream-error recovery, cancellation, reconnect, and live Redis revocation. |
| MySQL | 8.0, 8.4 | Same-origin discovery, native authentication, actual MySQL CLI, connection identity, lease-scoped metadata, text and binary prepared queries, binary parameters, safe session commands, read-only transactions and savepoints, transactional `INSERT`/`UPDATE`/`DELETE` through a write lease, denial and upstream-error recovery, reconnect, and live Redis revocation. |

The race suite, bounded PostgreSQL/MySQL/tunnel fuzz smoke tests, connection-ceiling load test, and proxy image read-only/non-root smoke test run alongside the matrix.

The matrix runs the actual `psql` and MySQL command-line executables from the matching database image in addition to the Go protocol clients. The Go clients exercise protocol features that the command-line tools do not expose directly, including binary prepared execution and PostgreSQL cancellation. These rows do not automatically qualify every JDBC or desktop client built on those protocols. Client qualification also covers the client's startup queries, metadata discovery, cancellation behavior, reconnect behavior, and exact version-specific defaults.

The expanded matrix was also run locally on September 11, 2026 against PostgreSQL 17 and MySQL 8.4 before it was added to CI. The CI matrix is authoritative for the complete version range.

## Desktop tools and drivers

The server speaks PostgreSQL and MySQL protocol, but a desktop client is not labelled **supported** until the version has repeatable qualification evidence for the native tunnel. This avoids promising behavior based only on direct-to-database testing.

| Client | Status | Required evidence before support is claimed |
| --- | --- | --- |
| `psql` | Qualified by automated and manual evidence | CI runs the matching `psql` 14–18 executable for startup, discovery, read-only and read+write transactions, denial recovery, and reconnect. The protocol harness covers extended/prepared/binary execution, cancellation, and revocation. |
| MySQL CLI | Qualified with cancellation limitation | CI runs the matching 8.0 and 8.4 executable for startup, discovery, read-only and read+write transactions, denial recovery, and reconnect. The protocol harness covers binary prepared execution and revocation. Server-side `KILL` remains blocked. |
| PostgreSQL JDBC | Pending manual qualification | Driver metadata, prepared statements, cancellation, TLS-disabled loopback profile, reconnect. |
| MySQL JDBC | Pending manual qualification | Driver metadata, prepared statements, cancellation, TLS-disabled loopback profile, reconnect. |
| DBeaver with PostgreSQL | Exploratory manual qualification | A simple `SELECT` succeeded; record the DBeaver and JDBC driver versions, then verify metadata, prepared parameters, cancellation, denial recovery, and reconnect. |
| pgAdmin with PostgreSQL | Exploratory manual qualification | A simple `SELECT` succeeded; record the pgAdmin version, then verify metadata, cancellation, denial recovery, and reconnect. Its known startup batch is covered in CI. |
| DBeaver with MySQL | Exploratory manual qualification | A simple `SELECT` succeeded; record the DBeaver and JDBC driver versions, then verify metadata, prepared parameters, denial recovery, and reconnect. DBeaver-style session comments are covered in CI. |
| MySQL Workbench | Exploratory manual qualification | A simple `SELECT` succeeded; record the Workbench version, then verify metadata, denial recovery, reconnect, and the documented cancellation limitation. |
| DataGrip and TablePlus | Pending manual qualification | Record the application and driver versions, then complete the relevant engine checklist. |

Do not infer support from a client merely connecting. Record the client version, operating system, CLI version, target engine/version, access mode, and result in the release evidence before updating this table.

`Exploratory manual qualification` means that the user has confirmed a real client can connect and run a simple query, but the complete versioned checklist has not been recorded. `Pending manual qualification` means that the protocol may work, but there is no repeatable client-specific evidence yet; qualification can uncover startup statements, metadata queries, or protocol behavior that needs an implementation change.

Manual psql smoke evidence from August 27, 2026: `psql` 18.4 on macOS successfully connected through the Crucible CLI loopback tunnel to the disposable PostgreSQL target, listed schemas, ran read-only statements, rejected multi-statement/write attempts under a read-only native lease, reconnected cleanly after a rejected statement, sent a Ctrl+C cancellation request for `pg_sleep(10)`, and reused the same connection after cancellation.

Manual MySQL CLI smoke evidence from August 27, 2026: MySQL CLI 8.0.34 on macOS successfully connected through the Crucible CLI loopback tunnel to the disposable MySQL 8.4 target using `--ssl-mode=DISABLED --get-server-public-key`, returned only the leased database from `SHOW DATABASES`, listed tables, returned column/index/create-table metadata for a leased-database table, ran read-only statements, rejected cross-database metadata and DML under a read-only native lease, and reconnected cleanly after a rejected statement.

Natural expiry is covered compositionally: the CLI enforces the access deadline, the control plane rejects expired leases and sessions, and the live integration suite proves that revocation closes active protocol connections. The integration suite does not wait for a wall-clock session to expire in every engine/version matrix cell because that would add minutes of idle CI time without exercising a different disconnect path.

JDBC and desktop-tool rows still require their own qualification because their startup and metadata traffic can differ from both harness clients and command-line clients. A simple query is useful evidence, but it is not enough to make a version-specific support claim.

## Manual desktop-client checklist

Use this checklist to promote one exact desktop-client and driver version from exploratory to qualified evidence:

1. Record the operating system, Crucible CLI version, desktop-client version, bundled driver version, target engine/version, and requested access mode.
2. Create a Native Client Query Access request, approve it through the normal workflow, and connect only to the CLI's loopback address with the temporary credentials.
3. Browse the leased database, schemas, tables, columns, and indexes. Confirm that another database cannot be browsed.
4. Run a simple parameter-free `SELECT` and the client's parameterized/prepared-statement workflow.
5. Start a read-only transaction, create and roll back to a savepoint, commit, and confirm the connection remains usable.
6. Attempt one statement that the lease must deny, then run a valid `SELECT` on the same connection to verify recovery.
7. For PostgreSQL, cancel a long-running query and reuse the connection. For MySQL, confirm the client reports the documented cancellation limitation without receiving broader administrative privileges.
8. Disconnect and reconnect while the lease is active, then end the Query Access session and confirm active connections are closed and reconnect is rejected.

Do not capture or retain the temporary username, password, access token, or target-database credential in screenshots or test notes.

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
| MySQL server-side query cancellation | `KILL` and `KILL QUERY` remain blocked as administrative statements. Disconnect the local client to abandon work and reconnect; clients whose cancel button requires a second privileged connection cannot cancel through the tunnel. PostgreSQL protocol cancellation is supported. |
