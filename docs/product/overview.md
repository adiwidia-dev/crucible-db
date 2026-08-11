# Crucible DB

Crucible DB is a database access control plane for engineering teams that need SQL execution to be reviewed, scheduled, proxied, and audited without handing out direct database credentials.

Primary users are admins, reviewers, developers, SREs, DBAs, and security operators. The product should feel precise, calm, operational, and trustworthy. It is not a marketing site and should not feel like a generic starter kit.

Register: product.

The core mental model is: every database action moves through a visible control plane. Access is scoped by role, risky work can require approval, scheduled work has an explicit execution window, and every meaningful user action leaves an audit event.

Experience principles:
- Approval state should be visible at a glance.
- Risk and access level should be obvious before execution.
- The next action should be near the object that needs it.
- Audit context should stay close to the operational workflow.
- Screens should be dense enough for repeated admin work, but not visually crowded.

Avoid generic grey tables, decorative dashboards, oversized marketing sections, toy SQL editors, hidden approval state, and any interaction that makes users wonder whether a database operation has actually run.
