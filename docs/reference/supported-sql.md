# Supported SQL

Crucible DB governs recognized statement families according to the workspace SQL policy and the effective role policy on the selected target.

## Recognized governed families

| Family | Typical use | Policy-controlled |
| --- | --- | --- |
| Read query | Inspect data or schema-supported result sets. | Yes |
| `INSERT` | Add data. | Yes |
| `UPDATE` | Change data. | Yes |
| `DELETE` | Remove data. | Yes |
| `CREATE TABLE` | Create a table. | Yes |
| `ALTER TABLE` | Change a table definition. | Yes |
| `DROP TABLE` | Remove a table. | Yes |
| `TRUNCATE TABLE` | Remove all table rows. | Yes |

`WITH` common-table-expression statements are governed by the top-level executable `SELECT`, `INSERT`, `UPDATE`, or `DELETE`.

Read statements execute inside a database-enforced read-only transaction where supported. PostgreSQL uses a read-only transaction, and MySQL enables read-only mode before starting its transaction. Crucible rolls the transaction back when a read fails.

`SHOW`, `DESCRIBE`, and `EXPLAIN` without `ANALYZE` are treated as reads. `CREATE TABLE` includes permanent and temporary table creation when that statement family is enabled.

## Always blocked categories

The following submitted SQL statement categories remain blocked in browser Query Access and Deployment Batch workflows, including when Emergency SQL fallback is enabled:

- administrative SQL;
- file-access SQL;
- security-management SQL;
- procedural SQL;
- transaction-control SQL, including `BEGIN`, `COMMIT`, `ROLLBACK`, and savepoints;
- multi-statement input; and
- `EXPLAIN ANALYZE`.

Emergency fallback only applies to one otherwise unsupported Deployment Batch statement. It treats that statement as write access, creates a warning, and keeps all permission, approval, and audit requirements. It never applies to Query Access.

## Query Access boundary

Each browser Query Access execution accepts exactly one SQL statement. Native client sessions apply the same family classification, effective role policy, session level, and permanent prohibitions to every executable PostgreSQL or MySQL protocol statement. Read-only sessions block data-changing SQL. Read + write sessions remain subject to current Query Access policy and all permanent SQL prohibitions.

Native client sessions are intentionally more compatible with desktop clients for safe session control: transaction and savepoint commands such as `BEGIN`, `COMMIT`, and `ROLLBACK` may pass as session commands, but they do not make a Deployment Batch atomic and they do not bypass authorization for any executable SQL statement inside the session.
