# Queues, workers, and schedules

Crucible DB separates HTTP requests, database execution, notifications, mail, and scheduled lifecycle work so long-running database operations do not occupy an Octane request worker.

## Runtime processes

The production application container uses Supervisor to keep three processes running:

| Process | Responsibility | Restart boundary |
| --- | --- | --- |
| Octane with FrankenPHP | Serves the Inertia application and HTTP endpoints. | Restart the container after code, environment, or configuration changes. |
| Horizon | Runs Redis-backed queue workers. | Supervisor restarts the process; deployments recreate the container. |
| Scheduler | Runs `schedule:work` and dispatches due lifecycle commands. | Supervisor restarts the process. |

## Queue separation

| Queue | Work | Current worker policy |
| --- | --- | --- |
| `queries` | Governed target-database execution. | One process, one attempt, 270-second timeout, recycled after 250 jobs or one hour. |
| `default` | General queued application work. | One process, one attempt, 60-second timeout. |
| `notifications` | In-app database notifications. | One process, three attempts, 60-second timeout. |
| `mail` | Optional email delivery. | One process, three attempts, 60-second timeout. |

All four queues use Redis. Query execution is deliberately isolated so email or notification work cannot execute SQL and query concurrency remains controlled.

## Scheduled lifecycle commands

Every minute, the scheduler runs:

- `crucible:dispatch-due-query-requests` to dispatch eligible scheduled work;
- `crucible:expire-query-sessions` to close expired access windows.

Both commands prevent overlap and use the single-server scheduler lock. The supplied Compose topology is single-node.

## Horizon dashboard

![Local Horizon queue dashboard](../assets/screenshots/operator-horizon.png){ .docs-screenshot }

The local development environment exposes `/horizon`. In non-local environments, the shipped Horizon gate contains no authorized email addresses, so the dashboard is denied by default. Deliberately configure `App\Providers\HorizonServiceProvider` before exposing it in production, and restrict access to trusted operators.

Useful checks inside the production application container include:

```bash
php artisan horizon:status
php artisan schedule:list
```

Horizon retains recent completed jobs for 60 minutes and failed jobs for seven days. Queue wait thresholds are 60 seconds for `queries`, `default`, and `notifications`, and 120 seconds for `mail`. Metrics require scheduled `horizon:snapshot`; the current Crucible scheduler does not schedule snapshots, so do not rely on historical Horizon metric graphs without adding that deliberate operational task.

## Long-running worker safety

Octane workers hold the booted application between requests. Crucible code must not retain users, authorization, request data, or dynamic target connections in static state or long-lived singletons. The production command recycles each Octane worker after 500 requests by default; `OCTANE_MAX_REQUESTS` can change that boundary.
