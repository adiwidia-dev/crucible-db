# Use Native Client access

Use **Native client** access when an approved Query Access session must be opened through a PostgreSQL or MySQL protocol client rather than the browser SQL editor. The current [compatibility matrix](../reference/native-client-compatibility.md) identifies which desktop clients and versions have repeatable qualification evidence.

Native client access is not a direct database connection. Crucible creates a time-bounded lease for the approved session, the Crucible CLI exposes a listener on your computer's loopback interface, and a private proxy validates every protocol statement against the same role, session, and SQL policy used by the application.

You can install the CLI before requesting access. Select **CLI** beside the notification bell in the application header, or use the [CLI installation and verification reference](../reference/crucible-cli.md).

![Native client session with a sanitized active client record](../assets/screenshots/native-client-session.png){ .docs-screenshot }

## Request access

1. Open **Work → Query requests → New request**.
2. Select **Native client**.
3. Select one approved database connection and choose **Read-only** or **Read + write** if policy exposes it.
4. Provide a purpose and submit the request.

Approval covers the connection, access level, and time window. It does not bypass the workspace SQL statement policy. Read-only sessions cannot execute writes. Write sessions remain subject to the effective role policy, required approval, and all permanently prohibited SQL categories.

## Start and connect

After approval, open the request and start the session. In the **Native client access** workspace:

1. Select **Create temporary credentials**. Copy the username and password immediately; Crucible never shows that password again.
2. If it is not installed yet, select **CLI** in the application header and install the matching release.
3. Run the command displayed in the workspace. It opens a browser confirmation and then listens only on `127.0.0.1`.
4. Create a connection in your client using the local address and copied credentials.

| Target driver | Host        | Default port | Client TLS setting         |
| ------------- | ----------- | ------------ | -------------------------- |
| PostgreSQL    | `127.0.0.1` | `5432`       | Disabled for the local hop |
| MySQL         | `127.0.0.1` | `3306`       | Disabled for the local hop |

The local hop is loopback-only. The CLI tunnel is authenticated and the proxy applies the configured upstream database TLS mode; disabling TLS in the client profile does **not** disable upstream TLS.

## Rotate, revoke, and expire

- **Rotate credentials** immediately invalidates the previous password and closes active proxy connections for that lease.
- **Revoke access** immediately closes connected clients, invalidates device tokens, and removes the temporary credential.
- **Expiry** has the same outcome when the approved session window ends.

The workspace records connected clients and a privacy-safe statement history: command class, SQL fingerprint, parameter count, duration, row count, and outcome. It intentionally does not display SQL values, parameter values, or result rows.

## Important limits

- Do not expose the CLI listener beyond `127.0.0.1`.
- A desktop client may cache credentials; reconnecting after rotation, revocation, or expiry will fail by design.
- Safe transaction and session-control commands are permitted so native clients behave like normal database connections. They do not bypass per-statement authorization, read-only enforcement, or auditing.
- Administrative, file-access, security-management, replication, and procedural statements remain blocked. Native client access does not make an arbitrary SQL client unrestricted.
- If a query fails, use the session history and audit log. Do not copy passwords, device codes, tokens, or production result data into support requests.

See [Native proxy operations](../operations/native-proxy.md) when you administer the service or need troubleshooting steps.
