# Review requests

Review is an independent control. You can review a request only when your effective role has reviewer authority for its target scope, and you cannot review your own request.

## What to examine

Before approving a Deployment Batch, confirm:

1. The target connection for every statement.
2. Statement order and the one-statement-per-row boundary.
3. SQL policy classification and every preflight warning.
4. Whether the schedule is appropriate for the target.
5. The requester’s stated purpose and rollback or recovery plan where relevant.

For Query Access, confirm:

1. Selected connections and requested duration.
2. Requested level, especially read + write.
3. Business purpose and expected scope.
4. Whether a fixed, reviewable Deployment Batch would be safer.

## Approve or reject

Choose **Approve** only when the stated scope is appropriate. Choose **Reject** with a clear reason when it is not. The decision creates notifications and an audit record.

Approval does not override later policy changes or safety checks. A Deployment Batch receives fresh preflight immediately before dispatch. Query Access checks each statement against the active session and current policy.

## Warnings versus blocks

Warnings are a decision aid. They should be understood and recorded in the request context when approved. Blocks are safeguards: they prevent a request from moving forward until the SQL, target, policy, or schedule problem is resolved.
