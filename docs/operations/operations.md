# Backup, recovery, and troubleshooting

## Back up before upgrades

The supplied production topology always persists encrypted control-database configuration, migration plans, and application files in the `crucible_storage` volume. When SQLite is active, that volume also contains the control database. Redis state lives in `crucible_redis`. When PostgreSQL or MySQL is active, its database volume or external managed service is a third backup target. Back up every active state store before upgrades, migrations, or host-level maintenance.

Document your own backup destination, retention, encryption, restoration test, and responsible operator. Volume names may include a Compose project prefix, so confirm the exact names with `docker volume ls` before running backup tooling.

A portable storage archive can be created with a temporary container after stopping application writes. Replace the placeholders with the exact inspected volume and approved backup directory:

```bash
docker compose --env-file .env.production -f compose.production.yaml stop app
docker run --rm \
  -v <crucible-storage-volume>:/source:ro \
  -v <approved-backup-directory>:/backup \
  alpine:3.22 tar -czf /backup/crucible-storage.tar.gz -C /source .
docker compose --env-file .env.production -f compose.production.yaml start app
```

Back up the Redis volume through the same controlled process or your platform's volume snapshot facility. If PostgreSQL or MySQL is active, take a database-native logical backup or a coordinated managed-service snapshot while application writes remain stopped. A `crucible_storage` archive alone is not a backup of a network control database.

Verify every archive or dump before an upgrade. Restoration should occur only on an isolated recovery host following your tested runbook. Preserve the original `APP_KEY`; without it, encrypted database selections, target credentials, SSO secrets, and settings cannot be decrypted.

!!! warning "Migration is not backup"
    The Application Database workflow copies and verifies live durable data and can synchronize it back during rollback. It does not provide retention, point-in-time recovery, or protection from operator mistakes affecting both sides.

!!! warning "Test restoration"
    A backup is useful only when it can be restored. Test the documented recovery process against a non-production host at a regular cadence.

## Health and logs

```bash
docker compose --env-file .env.production -f compose.production.yaml ps
docker compose --env-file .env.production -f compose.production.yaml logs --tail=100 app redis native-proxy
curl --fail http://127.0.0.1:8000/health
```

Use the request detail page and audit log first for workflow-specific failures. Use container logs when the application, Redis, workers, or scheduler may be unavailable.

## Common operational symptoms

| Symptom | First checks |
| --- | --- |
| A batch is approved but does not run | Requested schedule, explicit dispatch requirement, fresh preflight report, queue and Horizon health. |
| A query session cannot run SQL | Session expiry, declared access level, current role policy, and workspace SQL policy. |
| A previously ready batch is blocked | The latest fresh preflight, target reachability, policy change, and schedule condition. |
| Notifications are absent | Watch/subscription status, user preference, workspace email settings, and notification worker health. |
| App changes do not appear after deployment | Container image, application restart, health, and Octane long-running workers. |
| Application database migration cannot start | Active Query Access/native sessions, queued work, destination emptiness, and whether another plan is active. |
| Migration is waiting for restart | Restart every Laravel runtime, reload the migration page, and confirm the expected database fingerprint before finalizing. The Native proxy may be unhealthy while the fence blocks control requests. |

## Escalation information

When escalating an incident, include the request or session URL, timestamp, visible state, relevant audit event, and sanitized error text. Never include a target credential, `.env.production`, session cookie, recovery code, or database result containing sensitive data.
