# Administer Native Client access

Native client access reuses the Query Access approval workflow. Administrators decide whether it is available by assigning a per-connection **Native client access** level on roles.

## Set the policy

1. Open **Settings → Access roles** and edit a role policy for a connection group or explicit connection.
2. Set the role's maximum access. A read-only maximum access permits only native read access.
3. When the maximum access is write, choose **Native client access** as **Read-only** or **Read + write**.
4. Configure reviewer authority and read/write approval requirements as usual.

The native-client selector is deliberately separate from browser Query Access. A role may be allowed to submit write deployment batches while Native client access is restricted to read-only, preventing an approval of a future desktop session from implicitly authorizing unknown write SQL.

Connection-specific role policy overrides a group policy for that same connection. If any selected policy requires approval, the request needs approval.

## Operational controls

- Deactivating or reconfiguring a target connection revokes matching active native leases.
- Changing a role, connection-group membership, user role assignment, or the workspace SQL statement policy revokes affected leases.
- Expiry, cancellation, session end, and explicit revoke all invalidate credentials and close clients.
- **Audit logs** can be filtered to the **Native proxy** event family. Exports include only recorded metadata, never temporary passwords, bearer tokens, or statement parameter values.

## Review expectations

Reviewers see the target, requested native access level, duration, and requester justification before approving. They are approving a bounded connection scope, not a fixed SQL script. For work that must be reviewed statement-by-statement before execution, use a Deployment Batch instead.

## Enable the service

Set `NATIVE_PROXY_ENABLED=true`, a long random `NATIVE_PROXY_CONTROL_SECRET`, and the internal health URL described in [Configuration](../reference/configuration.md). The production Compose topology starts the private proxy service automatically. It needs no second DNS name, public database port, or separate TLS certificate.
