# Backup, recovery, and troubleshooting

## Back up before upgrades

The supplied production topology persists application state in the `crucible_storage` volume and Redis state in the `crucible_redis` volume. Back up both before upgrades or any host-level maintenance.

Document your own backup destination, retention, encryption, restoration test, and responsible operator. Volume names may include a Compose project prefix, so confirm the exact names with `docker volume ls` before running backup tooling.

A portable volume archive can be created with a temporary container after stopping application writes. Replace the placeholders with the exact inspected volume and approved backup directory:

```bash
docker compose -f compose.production.yaml stop app
docker run --rm \
  -v <crucible-storage-volume>:/source:ro \
  -v <approved-backup-directory>:/backup \
  alpine:3.22 tar -czf /backup/crucible-storage.tar.gz -C /source .
docker compose -f compose.production.yaml start app
```

Back up the Redis volume through the same controlled process or your platform's volume snapshot facility. Verify both archives before an upgrade. Restoration should occur only on an isolated recovery host following your tested runbook.

!!! warning "Test restoration"
    A backup is useful only when it can be restored. Test the documented recovery process against a non-production host at a regular cadence.

## Health and logs

```bash
docker compose -f compose.production.yaml ps
docker compose -f compose.production.yaml logs --tail=100 app redis
curl --fail http://localhost:8000/health
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

## Escalation information

When escalating an incident, include the request or session URL, timestamp, visible state, relevant audit event, and sanitized error text. Never include a target credential, `.env.production`, session cookie, recovery code, or database result containing sensitive data.
