# Crucible DB

Crucible DB is a database access control plane for engineering teams that need database work to be reviewed, scheduled, time-bounded, and audited without handing out direct database credentials.

Primary users are admins, reviewers, developers, SREs, DBAs, and security operators. The product should feel precise, calm, operational, and trustworthy. It is not a marketing site and should not feel like a generic starter kit.

The core mental model is: every database action moves through a visible control plane. Access is scoped by role and explicit connection group, with individual connection exceptions when necessary; risky work can require approval, scheduled work has an explicit execution window, query-access sessions declare their read-only or read + write scope, and every meaningful user action leaves an audit event.

## Current capabilities

- Deployment Batches group ordered, single SQL statements. Each statement selects its own permitted connection; execution stops after the first failed statement. A blocked batch can be retained as a non-executable draft, with an on-demand preflight recheck before strict submission.
- Query Access grants a time-bounded browser or Native client session over approved connections. The requester chooses read-only or read + write access only when every effective policy permits that capability, and the backend enforces both the granted session level and the current policy for every statement. Browser execution accepts exactly one statement; users can select one statement in the SQL editor to run it with the **Run selected** action or `Cmd/Ctrl + Enter`.
- Native client access is separately controlled on write-capable role policies. It creates a one-time temporary credential and a loopback-only Crucible CLI tunnel for desktop clients; the private PostgreSQL/MySQL proxy validates each statement, heartbeats session state, and records sanitized audit metadata.
- Connection groups are explicit lists of database connections. Role policies set defaults for a group; individual connection policies are deliberate exceptions and override that role's group policy for the same connection.
- Role policies set maximum deployment connection access, reviewer authority, approval requirements for reads and writes, and optional maximum write-session duration. A write-capable policy separately controls whether Query Access is read-only or may request read + write sessions; read-only is the default. Policy precedence is expressed as an ordered role list rather than an editable priority number.
- Approved deployment batches may run immediately or at a scheduled time. If approval arrives after a requested time, the batch waits for an explicit dispatch rather than silently running late.
- Deployment preflight evaluates SQL, target, policy, and schedule conditions at creation and immediately before execution. Workspace administrators choose each enabled governed SQL family individually: read, `INSERT`, `UPDATE`, `DELETE`, `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, and `TRUNCATE TABLE`. CTE-led statements are evaluated from their top-level executable statement. The optional Emergency SQL fallback is limited to Deployment Batches, treats otherwise unsupported SQL as write access, emits an explicit warning, and remains subject to role and approval policy. Warnings remain reviewable, while definite safety failures block approval or dispatch.
- Users can cancel eligible work, create linked retries, watch requests or connections, and manage optional email notification preferences. Administrators can control workspace email delivery and default timezone.
- First-run setup requires a deployment token and lets an administrator choose SQLite, PostgreSQL, or MySQL for Crucible's own control-plane data. Existing managed installations can migrate between those drivers through an exclusive, maintenance-fenced copy, verification, restart-aware cutover, history, and data-preserving rollback workflow.

Crucible DB supports PostgreSQL and MySQL targets. Approved Native client sessions provide a private protocol proxy for external database clients through the Crucible CLI; it does not expose a public database port or create target-database users.

Administrative, file-access, security-management, procedural, transaction-control, and `EXPLAIN ANALYZE` SQL remain blocked, including when Emergency SQL fallback is enabled. Query Access sessions cannot use that fallback.

Experience principles:
- Approval state should be visible at a glance.
- Risk and access level should be obvious before execution.
- The next action should be near the object that needs it.
- Audit context should stay close to the operational workflow.
- Screens should be dense enough for repeated admin work, but not visually crowded.

Avoid generic grey tables, decorative dashboards, oversized marketing sections, toy SQL editors, hidden approval state, and any interaction that makes users wonder whether a database operation has actually run.
