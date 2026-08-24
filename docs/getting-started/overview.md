# Learn Crucible DB

Crucible DB has two ways to perform database work. Choose the one that matches the purpose of the work before you begin.

| Workflow | Use it when | What is controlled |
| --- | --- | --- |
| **Deployment Batch** | You have a known, ordered set of SQL statements to change a database. | Each statement, its target, preflight, approval, schedule, execution, and audit record. |
| **Query Access** | You need temporary browser access to inspect data or perform approved interactive work. | Connections, session duration, declared read-only or read + write level, each query, expiry, and audit record. |

## Deployment Batch at a glance

A Deployment Batch contains one or more ordered, single SQL statements. Each statement chooses a target connection. The batch runs sequentially and stops when a statement fails. It is useful for a reviewed migration, repair, or controlled data change.

Before submission, Crucible DB evaluates the SQL, target, schedule, and effective role policy. A blocked report prevents submission, approval, and execution. You can still preserve the work as a draft.

## Query Access at a glance

Query Access gives you an expiring, browser-based SQL session for selected connections. You request either:

- **Read-only** to inspect schemas and data.
- **Read + write** only when every selected connection's effective policy explicitly permits it.

Approval, when required, approves the access scope. Every query is then checked again against the active session level and current workspace policy.

!!! warning "Approval is not a blank cheque"
    Approval never bypasses SQL policy, a session's declared access level, target permission, or expiry. A previously approved request can still be blocked if policy changes before it runs.

## The main states you will see

- **Draft**: saved only. It cannot be reviewed or executed.
- **Pending review**: waiting for an eligible independent reviewer.
- **Approved / ready**: allowed to run now or when an authorized person dispatches it.
- **Scheduled**: approved and waiting for its requested execution time.
- **Running / completed / failed**: execution is in progress or has an immutable result.
- **Cancelled / rejected**: work will not run. Use the audit record to understand why.

See the full [status and state reference](../reference/statuses.md) for transitions and next actions.
