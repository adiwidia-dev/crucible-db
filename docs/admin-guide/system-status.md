# System Status

**Who sees it:** workspace administrators under **Manage → Administration → Application → System Status**.

System Status is a read-only operational view. It shows the current Crucible DB application version and cached status snapshots for the application runtime, control database, Redis, Horizon, scheduler, and native proxy.

## How to read a status

| Status | Meaning |
| --- | --- |
| Healthy | The most recent bounded check succeeded. |
| Degraded | The component responded, but requires attention, such as a paused Horizon supervisor or a native-proxy version mismatch. |
| Unhealthy | The most recent bounded check failed, or no scheduler heartbeat arrived in its expected interval. |
| Unknown | No recent snapshot is available. Treat this as needing investigation, not as healthy. |
| Disabled | The optional native proxy is not enabled for this workspace. |

The page never launches Docker commands, executes a fresh proxy request, or exposes connection strings, credentials, SQL, query parameters, or result data. The scheduler refreshes the cached snapshot every minute.

## Component coverage

- **Application runtime** confirms that the page was served and shows the configured Octane driver, PHP version, and Crucible DB version.
- **Control database** performs a bounded `SELECT 1` from the scheduler process.
- **Redis** performs a bounded ping from the scheduler process.
- **Horizon** reads its active master and supervisor state from Redis. It reports paused workers as degraded.
- **Scheduler** is healthy only after its independent, recent heartbeat is observed. Running another command manually does not create that heartbeat.
- **Native proxy** reuses the existing bounded readiness snapshot, including the reported proxy version and instance ID when available.

## Follow-up checks

System Status is intended to identify the affected component, not to replace detailed investigation. Use the production container for more detail:

```bash
php artisan horizon:status
php artisan schedule:list
php artisan crucible:check-system-status
```

Use the [queues, workers, and schedules](../operations/queues-and-workers.md) guide for Horizon and scheduler troubleshooting, and [Native proxy operations](../operations/native-proxy.md) for native-client readiness.
