# Overview dashboard

**Who sees it:** every signed-in user.

Overview is the operational landing page. It summarizes work that needs attention without requiring you to search every request.

![Ordinary user Overview dashboard](../assets/screenshots/user-overview.png){ .docs-screenshot }

## Summary counts

The top summary identifies pending reviews, scheduled work, failed executions, and active Query Access sessions visible to you. When Native client access is enabled, administrators also see the active proxy-connection count. Counts respect authorization, so an ordinary user does not receive a workspace-wide administrator view.

When Native client access is enabled, the header shows a status icon immediately before the **CLI** link. Hover over it, or focus it with the keyboard, to inspect proxy readiness, the reported proxy version, active instances, active connections, and the last health check. An unhealthy or version-mismatched state remains visible while operators investigate it.

## Needs attention

The dashboard groups actionable work:

- **Pending review** for reviewer-capable users.
- **Scheduled work** waiting for its execution time or explicit dispatch.
- **Failed execution** requiring investigation or a linked retry.
- **Active sessions** approaching expiry.

Select an item to open its full request or session context. Review the target, status, latest preflight, and next permitted action there.

## Create new work

Use **New request** to start a Deployment Batch or Query Access request. Choose the workflow based on whether the SQL is known in advance. See [Learn Crucible DB](../getting-started/overview.md) for the decision.
