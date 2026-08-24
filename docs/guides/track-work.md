# Track work and notifications

Use the request detail page as the source of truth for work state. It keeps preflight findings, review decisions, execution outcomes, and audit events adjacent to the request.

## Watch important work

Use **Watch** on a request or connection to receive resource-specific in-app notifications. Notifications can include review requests, decisions, execution outcomes, session lifecycle events, preflight blocks, retries, and connection test failures.

Your notification preferences control optional email delivery. Workspace administrators can also enable or disable workspace email delivery.

## Understand the audit trail

Audit records preserve meaningful user and system events. Expect them for request creation, preflight, review, dispatch, execution, cancellation, retry, session lifecycle, connection administration, and policy changes.

Credentials are encrypted at rest and are not exposed in request details, notifications, exports, or audit payloads.

## When something goes wrong

1. Read the latest preflight finding or execution error on the request.
2. Confirm the target connection and current request state.
3. Check whether the action is blocked by policy, approval, schedule, or expired access.
4. Use a linked retry only when it represents the appropriate next work item.
5. Escalate with the request URL and audit context, never by copying credentials into a ticket.
