# Access roles

**Who sees it:** workspace administrators under **Manage → Administration → Access & identity → Access Roles**.

![Access Roles page](../assets/screenshots/admin-access-roles.png){ .docs-screenshot }

## Policy controls

For each connection group or individual connection, a role can define:

- maximum deployment access;
- Query Access capability;
- reviewer authority;
- read approval requirement;
- write approval requirement; and
- maximum write-enabled session duration.

![Role policy editor](../assets/screenshots/admin-role-policy.png){ .docs-screenshot }

Read-only Query Access is the default for write-capable roles. Enable interactive read + write only when the operational need justifies approving access before the exact SQL is known.

An individual connection policy overrides the same role's group policy for that connection. See [Roles, groups, and connections](../concepts/access-policy.md) for evaluation order.

Policy changes take effect at the next governed action boundary. Crucible re-evaluates the original requester and every target before dispatch, queued execution, retry, or Query Access session start. Tightening approval, access, or duration can return work to review or prevent it from starting; existing request records are not permanent policy exceptions.

## Lifecycle safeguards

The built-in administrator role cannot be edited or deleted through the role UI. A custom role cannot be deleted while users, individual connection permissions, or connection-group policies still reference it. Remove or migrate those assignments deliberately first.

Administrators cannot change their own role assignment from People. This prevents an accidental self-lockout during routine access administration.
