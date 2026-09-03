# Status and state reference

## Deployment Batch states

| State | Meaning | Typical next action |
| --- | --- | --- |
| Draft | Saved, editable, and non-executable. | Edit, run preflight, submit, or cancel. |
| Pending review | Submitted and awaiting an independent eligible reviewer. | Review, reject, or cancel when eligible. |
| Approved / ready | Approval is complete and the batch may be dispatched. | Dispatch now or wait for the requested schedule. |
| Scheduled | Approved and waiting for its requested time. | Wait, or inspect if it becomes overdue. |
| Running | Queued work is executing statements in order. | Observe results. |
| Completed | All statements completed successfully. | Audit or export recorded results as permitted. |
| Failed | A statement failed; later statements did not run. | Investigate and consider a linked retry. |
| Rejected | A reviewer declined the request. | Address the reason in a new or edited request. |
| Cancelled | Eligible work was cancelled with a reason. | Start new work only when needed. |

## Query Access request and session states

| State | Meaning | Typical next action |
| --- | --- | --- |
| Pending review | Access scope needs independent approval. | Wait for a reviewer or cancel. |
| Approved | The approved scope can start a session. | Start the session. |
| Active session | SQL can run only within expiry, declared level, and policy. | Run one permitted statement at a time or end the session. |
| Ended | The session was explicitly ended. | Create a fresh linked request if more work is needed. |
| Expired | The approved access window elapsed. | Create a fresh request. |
| Rejected / cancelled | Access will not start. | Read the reason and request the correct scope if appropriate. |

State labels describe whether work may proceed; they do not replace the latest policy or SQL evaluation.

## Native client lease and connection states

| State | Meaning | Typical next action |
| --- | --- | --- |
| Active lease | Temporary credentials may authorize a native client until the session expires. | Start the CLI tunnel or rotate/revoke credentials. |
| Revoked lease | Credentials, device tokens, and connected clients have been invalidated. | Create a new request when access is still needed. |
| Expired lease | The approved access window ended. | Create a fresh request. |
| Reserved connection | The proxy admitted a client and is awaiting protocol authentication. | Wait briefly; stale reservations are pruned. |
| Active connection | A native database client is connected through the lease. | Observe the safe statement history or revoke if necessary. |
| Closed / failed | The client disconnected, was denied, or its reservation timed out. | Review audit metadata and reconnect only while the lease remains active. |
