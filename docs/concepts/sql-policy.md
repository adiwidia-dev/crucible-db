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

## Candidate observation and explicit review

Preflight can record a deduplicated candidate for structurally reviewable SQL that is not covered by built-in or custom rules. A normal draft can retain that observation without interrupting administrators.

**Request SQL policy review** is the explicit handoff. It saves the current Deployment Batch as a draft in the same transaction, marks the current candidate occurrences as requested, records the handoff, and notifies administrators. The candidate represents the shared SQL identity; the request marker preserves which draft and requester asked for a decision.

Policy decisions and deployment approval are intentionally separate. An allow decision changes policy and refreshes affected preflight reports. It does not submit, approve, schedule, or execute any draft. Denial or dismissal keeps the batch blocked and returns the administrator's decision note to the requester.
