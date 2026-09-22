# Overview dashboard

**Who sees it:** every signed-in user.

Overview is the operational landing page. It summarizes work that needs attention without requiring you to search every request.

![Ordinary user Overview dashboard](../assets/screenshots/user-overview.png){ .docs-screenshot }

## Operational summary

The top summary keeps the most important signals in three groups:

- **Attention** combines requests awaiting your review, unresolved SQL policy reviews visible to administrators, and failed executions.
- **Upcoming** counts approved executions waiting for their scheduled time.
- **Live access** counts active Query Access sessions and, when Native client access is enabled, shows the active proxy connections visible to you.

The links within each summary group open the relevant filtered workflow. Counts respect authorization, so an ordinary user does not receive a workspace-wide administrator view.

When Native client access is enabled, the header shows a status icon immediately before the **CLI** link. Hover over it, or focus it with the keyboard, to inspect proxy readiness, the reported proxy version, active instances, active connections, and the last health check. An unhealthy or version-mismatched state remains visible while operators investigate it.

## Operational queue

One prioritized queue brings the current work together. Each row is tagged as **Failed**, **Review**, **Policy**, **Scheduled**, or **Session**, with its access level or query type where relevant. Failed executions appear first, followed by approval work, SQL policy review, live sessions, and scheduled executions.

Use **All**, **Attention**, **Scheduled**, and **Live** to narrow the queue without leaving Overview. Select an item to open the exact request, SQL policy candidate, or Query Access session workflow. The dashboard shows the ten highest-priority items in the selected view; use Query Requests or SQL Policy for the full history.

## Create new work

Use **New request** to start a Deployment Batch or Query Access request. Choose the workflow based on whether the SQL is known in advance. See [Learn Crucible DB](../getting-started/overview.md) for the decision.
