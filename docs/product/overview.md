# Crucible DB

Crucible DB is a database access control plane for engineering teams that need database work to be reviewed, scheduled, time-bounded, and audited without handing out direct database credentials.

Primary users are admins, reviewers, developers, SREs, DBAs, and security operators. The product should feel precise, calm, operational, and trustworthy. It is not a marketing site and should not feel like a generic starter kit.

The core mental model is: every database action moves through a visible control plane. Access is scoped by role and explicit connection group, with individual connection exceptions when necessary; risky work can require approval, scheduled work has an explicit execution window, query-access sessions declare their read-only or read + write scope, and every meaningful user action leaves an audit event.

## Current capabilities

- Deployment Batches group ordered, single SQL statements. Each statement selects its own permitted connection; execution stops after the first failed statement.
- Query Access grants a time-bounded browser session over one or more connections. The requester chooses read-only or read + write access, and the backend enforces it for every session query.
- Connection groups are explicit lists of database connections. Role policies set defaults for a group; individual connection policies are deliberate exceptions and override that role's group policy for the same connection.
- Role policies set maximum connection access, reviewer authority, approval requirements for reads and writes, and optional maximum write-session duration. Policy precedence is expressed as an ordered role list rather than an editable priority number.
- Approved deployment batches may run immediately or at a scheduled time. If approval arrives after a requested time, the batch waits for an explicit dispatch rather than silently running late.
- Deployment preflight evaluates SQL, target, policy, and schedule conditions at creation and immediately before execution. Workspace administrators choose the enabled governed SQL families: `SELECT`/read, `INSERT`, `UPDATE`, `DELETE`, `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, and `TRUNCATE TABLE`. Warnings remain reviewable, while definite safety failures block approval or dispatch.
- Users can cancel eligible work, create linked retries, watch requests or connections, and manage optional email notification preferences. Administrators can control workspace email delivery and default timezone.

Crucible DB supports PostgreSQL and MySQL targets. It does not currently provide a protocol proxy for external database clients; all query access occurs through the application session.

Experience principles:
- Approval state should be visible at a glance.
- Risk and access level should be obvious before execution.
- The next action should be near the object that needs it.
- Audit context should stay close to the operational workflow.
- Screens should be dense enough for repeated admin work, but not visually crowded.

Avoid generic grey tables, decorative dashboards, oversized marketing sections, toy SQL editors, hidden approval state, and any interaction that makes users wonder whether a database operation has actually run.
