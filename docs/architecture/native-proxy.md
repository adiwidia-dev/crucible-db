# Native proxy architecture

Native client access extends Query Access rather than introducing a second authorization model. A Query Request still determines the target, read/write level, approver requirement, requester, and expiry. Starting the session creates a `QuerySession`; temporary credentials create a `NativeProxyLease` bound to that session.

## Trust boundaries

| Boundary | Responsibility |
| --- | --- |
| Desktop client to CLI | Loopback-only protocol listener. No inbound network exposure. |
| CLI to gateway | Same-origin, authenticated WebSocket tunnel after device authorization. |
| Gateway to proxy | Private Compose network route only. |
| Proxy to Laravel | HMAC-authenticated, AES-256-GCM-encrypted control messages with replay-safe request IDs, clock-skew checks, proxy allowlists, and rate limiting. |
| Proxy to target | Uses the encrypted target connection configuration and configured upstream TLS mode. |

The proxy never creates or manages target-database users. It authenticates synthetic temporary credentials, then connects using the preconfigured target credential. Sensitive proxy-to-Laravel request and response bodies are encrypted as well as signed. Target credentials, synthetic password hashes, protocol secrets, device codes, and bearer tokens are encrypted or hashed and are not included in browser page properties, audits, notifications, logs, or statement history.

## Protocol enforcement

The PostgreSQL and MySQL handlers create a control-plane reservation before authenticating each client, then fetch upstream material through a separate short-lived private control call. They heartbeat while connected, validate each statement, record only sanitized execution metadata and byte counters, and close the connection when policy, role scope, lease state, connection state, or expiry no longer allows it.

Redis improves revocation latency; durable heartbeat evaluation prevents Redis availability from becoming an authorization dependency. A missed or rejected heartbeat closes the client connection.
