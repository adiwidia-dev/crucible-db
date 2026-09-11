# Notification policy

**Who sees it:** workspace administrators under **Manage → Administration → Application → Notification Policy**.

![Notification Policy settings](../assets/screenshots/admin-notification-policy.png){ .docs-screenshot }

Control workspace-wide in-app and email delivery plus event defaults for review requests, successful and failed execution, Query Access lifecycle, and connection failures.

Individual preferences can narrow optional email delivery but cannot create delivery the workspace has disabled. Audit records are independent of notification delivery.

## Delivery channels

- **In-app notifications** power the notification bell and history page.
- **Email notifications** permit eligible events to use the SMTP transport configured under Application.

## Event policy

Review and decision, completed batch, failed batch, Query Access, and failed connection-test events can be controlled independently. Disabling delivery does not suppress the corresponding audit events.

## Operational alert recipients

Select administrators who should receive critical failed-batch, session-expiry, and connection-failure alerts. If no administrators are selected, every active administrator becomes an operational recipient. Too many recipients create noise; too few create a single point of failure.

Notification payloads deliberately exclude SQL text, target credentials, and result data. Recipients follow the contextual link and rely on their own authorization to open the resource.
