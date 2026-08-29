# Cron jobs

Cron jobs are stored in the database and executed by a single `worker.php` file. This keeps the
feature compatible with shared PHP hosting, where the hosting panel usually lets you schedule one
PHP file or one URL, but does not provide long-running workers, containers, process managers, or
multiple services.

Each job belongs to a project and has:

- `type` — one of `http`, `command`, `callback`, or `function`.
- `target` — the URL, shell command, or PHP callback target.
- `queue` — an optional queue name, defaulting to `default`.
- `run_at` — a one-time execution date/time.
- `interval_seconds` — when present, the job is recurring and is rescheduled after each successful run.
- `max_retries` — retry limit after failures. Use `null` or a negative value internally for unlimited retries.
- `retry_backoff` — `exponential` by default, or `fixed`.
- `retry_delay_seconds` and `max_retry_delay_seconds` — base retry delay and retry delay cap.
- `timeout_seconds` — execution timeout for HTTP and command jobs.
- `allow_overlap` — whether another worker may start the same job while a previous lock is still active.

Jobs are claimed with an atomic database update before execution. That is deliberate: hosting cron
systems can trigger the same file more than once, and the lock prevents duplicate execution unless
`allow_overlap` is enabled. Every execution is stored in `cron_job_runs` with status, duration,
output, error, attempt number, and worker id.

Platform-authenticated API endpoints:

| Method | Route                                      | Description                  |
| ------ | ---------------------------------------- --| ---------------------------- |
| GET    | `/projects/{id}/cron-jobs`                 | List a project's cron jobs   |
| POST   | `/projects/{id}/cron-jobs`                 | Create a cron job            |
| GET    | `/projects/{id}/cron-jobs/{job_id}`        | Get a cron job               |
| PATCH  | `/projects/{id}/cron-jobs/{job_id}`        | Update a cron job            |
| DELETE | `/projects/{id}/cron-jobs/{job_id}`        | Delete a cron job            |
| GET    | `/projects/{id}/cron-jobs/{job_id}/runs`   | List execution history       |

## Example recurring HTTP job

```bash
curl -X POST "$APP_URL/api/v1/projects/1/cron-jobs" \
  -H "Authorization: Bearer $PLATFORM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Ping webhook",
    "type": "http",
    "target": "https://example.com/webhook",
    "method": "POST",
    "headers": { "Content-Type": "application/json" },
    "payload": { "source": "loxodontu" },
    "queue": "default",
    "interval_seconds": 300
  }'
```

## Example PHP callback job

```json
{
  "name": "Daily cleanup",
  "type": "callback",
  "target": "App\\Jobs\\DailyCleanup::handle",
  "queue": "maintenance",
  "run_at": "2026-08-29 03:00:00",
  "interval_seconds": 86400
}
```

## Example function job

```json
{
  "name": "Run cleanup",
  "type": "function",
  "target": "daily-cleanup",
  "queue": "maintenance",
  "run_at": "2026-08-29 03:00:00",
  "interval_seconds": 86400,
  "payload": { "source": "cron" }
}
```

The callback must be autoloadable and callable. It receives the decoded payload and the job row:

```php
namespace App\Jobs;

use App\Cron\JobResult;

class DailyCleanup
{
    public static function handle(?array $payload, array $job): JobResult
    {
        return JobResult::success('Cleanup finished.');
    }
}
```

Internal code can schedule jobs without going through HTTP:

```php
use App\Cron\CronJob;

CronJob::schedule(
    projectId: 1,
    name: 'Daily cleanup',
    type: 'callback',
    target: App\Jobs\DailyCleanup::class . '::handle',
    queue: 'maintenance',
    intervalSeconds: 86400
);
```

## Running the worker

Run the worker from CLI:

```bash
php worker.php --token="$CRON_WORKER_TOKEN" --limit=10
php worker.php --token="$CRON_WORKER_TOKEN" --queue=maintenance --limit=5
```

Or configure a shared-hosting cron entry to call the public URL:

```text
https://example.com/worker.php?token=YOUR_TOKEN&limit=10
https://example.com/worker.php?token=YOUR_TOKEN&queue=maintenance&limit=5
```

Worker environment variables:

```dotenv
CRON_WORKER_TOKEN=
CRON_WORKER_LIMIT=10
CRON_ALLOW_COMMANDS=false
```

`CRON_WORKER_TOKEN` is optional, but should be set in production if `worker.php` is reachable from
the web. `CRON_WORKER_LIMIT` controls how many due jobs a single worker invocation may claim and
execute before returning; keep it small on shared hosting so each cron request finishes quickly.
`command` jobs are disabled by default because they execute shell commands on the host; enable them
only in trusted deployments with `CRON_ALLOW_COMMANDS=true`.
