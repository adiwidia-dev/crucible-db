# Administrator navigation

**Who sees it:** workspace administrators.

Administrators receive the complete operational workspace plus configuration menus grouped by responsibility.

![Complete administrator navigation](../assets/screenshots/admin-navigation.png){ .docs-screenshot }

## Data

- **Connections** controls target database definitions and health checks.
- **Connection Groups** controls explicit reusable target membership.

## Admin → Access

- **People** manages invitations, role assignment order, and user enablement.
- **Access Roles** defines connection and group policy.

## Admin → Authentication

- **Authentication** controls allowed sign-in methods and configured SSO providers.

## Admin → Workspace

- **Application** controls workspace identity, timezone, mail transport, and factory reset.
- **Notification Policy** controls workspace delivery and event defaults.

## Admin → Governance

- **SQL Policy** controls governed statement families and Emergency SQL fallback.
- **Audit Log** provides filterable and exportable administrative history.

!!! danger "Administration does not remove operational accountability"
    Administrator actions are audited. Use the least access needed and keep production-impacting work in the normal request and review workflows.
