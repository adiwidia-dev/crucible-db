# SQL statement policy

The workspace SQL policy decides which recognized statement families Crucible DB may govern. It is a workspace control, not a client-side editor preference, and is enforced by the backend.

## Governed statement families

An administrator can enable each of these families independently:

- Read queries
- `INSERT`
- `UPDATE`
- `DELETE`
- `CREATE TABLE`
- `ALTER TABLE`
- `DROP TABLE`
- `TRUNCATE TABLE`

Each family is enabled or disabled explicitly. This keeps the effective policy visible and avoids a second override that could disagree with the individual settings.

## CTE-aware detection

Statements beginning with `WITH` are classified by the top-level executable statement. This means `WITH … SELECT`, `WITH … INSERT`, `WITH … UPDATE`, and `WITH … DELETE` receive the same role policy and preflight checks as their equivalent non-CTE statements.

## Emergency SQL fallback

Emergency fallback is a separate global setting for a narrow operational exception. It may admit one otherwise unsupported **Deployment Batch** statement as write access.

It always:

- requires effective write permission;
- requires approval when applicable;
- creates an explicit preflight warning; and
- writes audit events.

It never applies to Query Access sessions.

!!! danger "Emergency fallback is not break-glass access"
    It does not permit administrative, file-access, security-management, procedural, transaction-control, multi-statement, or `EXPLAIN ANALYZE` SQL. Those categories remain blocked even when fallback is enabled.

## Review policy changes deliberately

When changing SQL policy, consider active drafts, approved batches, and sessions. New checks occur when work is submitted or executed, so a policy change can appropriately block previously prepared work. Use the audit trail to explain the decision.
