# Administrator navigation

**Who sees it:** workspace administrators.

Administrators receive the complete operational workspace plus a compact, collapsible administration tree in the main sidebar.

![Complete administrator navigation](../assets/screenshots/admin-navigation.png){ .docs-screenshot }

## Data

- **Connections** controls target database definitions and health checks.
- **Connection Groups** controls explicit reusable target membership.

## Manage → Administration

Administration starts collapsed unless the current page is inside it. Its four subsections are also independent disclosures, so administrators can keep the categories they need open without losing their place.

### Access & identity

- **People** manages invitations, role assignment order, and user enablement.
- **Access Roles** defines connection and group policy.
- **Access Workflows** controls workspace approval behavior.

### Security & policy

- **Sign-in Methods** controls password and passkey availability.
- **SSO Providers** manages Google, GitHub, and Microsoft provider configuration.
- **SQL Policy** controls governed statement families and Emergency SQL fallback.

### Application

- **General** controls workspace identity, timezone, mail transport, and factory reset.
- **Notification Policy** controls workspace delivery and event defaults.
- **Database** shows the active control database and provides managed, verified migration and rollback workflows.
- **System Status** provides read-only, cached health snapshots for the application runtime, Redis, Horizon, scheduler, native proxy, and generated Wayfinder contracts.

### Governance

- **Audit Log** provides filterable and exportable administrative history.

## Account

**Account** remains in the sidebar footer with Profile, Preferences, and Security. It can stay expanded at the same time as Administration.

Native client access is configured from **Access Roles**. Its per-connection access level is separate from browser Query Access, and native proxy lifecycle events can be filtered in **Audit Log**.

!!! danger "Administration does not remove operational accountability"
    Administrator actions are audited. Use the least access needed and keep production-impacting work in the normal request and review workflows.
