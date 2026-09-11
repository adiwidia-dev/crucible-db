# Native client threat model

This document defines the security properties and operating limits of Native client access. It complements the application-wide security guidance; it is not a substitute for reviewing the target database’s own network, identity, and backup controls.

## Assets that must remain confidential

- Target connection passwords and TLS private keys.
- One-time synthetic native-client passwords.
- Device codes and CLI bearer tokens.
- Native proxy control secret and control-request signatures.
- SQL parameter values, result rows, and client-side result exports.

Crucible stores synthetic passwords and bearer tokens only as hashes, encrypts configured connection secrets, and keeps plaintext credentials in the browser component only while it is displayed. Page props, audit metadata, notifications, statement history, and logs must not include any item in this list.

## Trust boundaries

| Boundary | Control |
| --- | --- |
| Database client → CLI | A TCP listener restricted to `127.0.0.1` or `::1`; no LAN listener is accepted. |
| CLI → Crucible | Same-origin device authorization and authenticated WebSocket tunnel. The client uses the configured `APP_URL`; no proxy subdomain is required. |
| Gateway → native proxy | Internal Compose network only. The discovery and `/native-tunnel/*` paths are the sole externally routed proxy paths. |
| Native proxy → Laravel | Signed and AES-256-GCM-encrypted control messages with timestamp, nonce replay prevention, proxy identity/IP allowlists, authorization checks, and rate limits. |
| Native proxy → target database | Target credential and independent upstream TLS configuration. The proxy does not create target users or roles. |
| Laravel → Redis | Immediate revocation publish/subscribe; durable heartbeat checks remain the fail-closed authorization backstop. |

## Threats and mitigations

| Threat | Mitigation | Residual responsibility |
| --- | --- | --- |
| Stolen temporary password | Device authorization plus short lease/token expiry; one-time reveal; rotate or revoke the lease immediately. | Protect the requester’s workstation and browser session. |
| Replayed tunnel/control request | Bearer hash verification, short-lived device token, signed timestamped nonce, and idempotent connection identifiers. | Keep the control secret unique and rotate it during an incident. |
| Lost revocation event | Redis provides fast closure; each proxy connection reauthorizes by heartbeat and is closed on a revoked/expired policy result. | Monitor Redis and proxy health. |
| Privilege escalation through a native session | Role-native-proxy access is separate from maximum and browser Query Access policies; every statement is checked against session access, role policy, SQL policy, and current connection state. | Configure least privilege and approval requirements. |
| Direct proxy exposure | Proxy protocol and gateway listener ports are private; desktop traffic goes through the loopback CLI and application origin. | Do not add host port mappings or bypass the gateway. |
| Secret/result leakage through observability | Sanitized fingerprints, counts, timings, and event metadata only. | Keep external log aggregation and browser extensions within your security boundary. |
| Malicious target or upstream TLS downgrade | TLS mode, CA, client certificate, and key remain connection configuration. | Use verified upstream TLS for production targets and protect CA material. |

## Incident response

1. Revoke the affected Native Client lease or end the Query Access session. This invalidates issued tokens and closes tracked proxy connections.
2. If necessary, deactivate the connection or remove affected role/group access; these actions revoke matching leases.
3. Inspect `native_proxy.*` audit events, session metadata, proxy health, and target database logs. Do not collect one-time passwords, bearer tokens, parameter values, or result rows in incident tickets.
4. Rotate the native proxy control secret if its confidentiality is uncertain, restart the app and proxy, and verify health before restoring access.
5. Preserve normal application and Redis backups for recovery; the proxy itself has no durable credential store.

## Security invariants for changes

Changes to Native client access must retain these properties:

- No public database-protocol port, second public proxy hostname, or direct target-database route.
- Server-side authorization and statement validation for every operation.
- No plaintext secret in an Inertia prop, notification, audit event, application log, CLI JSON event, or persisted statement record.
- Revocation and expiry close live access promptly and fail closed when durable authorization cannot continue.
- Tests cover the permitted path and the relevant denied path before behavior changes.
