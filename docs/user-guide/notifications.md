# Notifications

**Who sees it:** every signed-in user.

Notifications keep review, execution, session, cancellation, retry, and connection events close to the affected work.

![Notification history](../assets/screenshots/user-notifications.png){ .docs-screenshot }

## Notification bell

The top-bar bell shows unread count and recent items. Request-related notifications include the stable `#ID` used in the Query Requests table and request URL. Open an item to go directly to its request, session, or connection. Use **Mark all read** only after reviewing the outstanding items.

![Notification bell menu](../assets/screenshots/notification-menu.png){ .docs-screenshot }

Severity is expressed with text and iconography in addition to colour. Marking an item read changes notification state only; it does not acknowledge, approve, dispatch, or cancel the underlying operation.

## Notification history

Select **View history** from the bell to open the complete notification page. Each notification includes severity, message, timestamp, read state, a contextual action, and the stable request ID when it relates to a Query Request.

## Email delivery

Email requires both workspace email delivery and your individual preference to be enabled. Request-related email subjects and bodies include the same `#ID`, so a recipient can find the request without extracting the identifier from its link. In-app notification history remains the primary product record. Audit records remain separate and authoritative for meaningful actions.
