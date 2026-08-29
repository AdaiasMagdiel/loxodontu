<p align="center">
  <img src="banner-loxodontu.webp" alt="Loxodontu" width="100%">
</p>

Loxodontu is an open-source Backend-as-a-Service (BaaS) built with modern PHP. Designed as
a lightweight, self-hostable alternative to Supabase and Firebase, it provides developers
with instant APIs, database management, and essential backend infrastructure right out of
the box—leveraging the speed, simplicity, and ecosystem of PHP 8+.

Early development. Just started, no stable release yet, expect things to change.

## Project philosophy

Loxodontu is intentionally self-hosted and built entirely on PHP. Docker, serverless
platforms, managed databases, and modern edge runtimes have changed how many teams
deploy software, but they are still not the most accessible path for everyone. In many
places, and for many independent developers, shared PHP hosting remains the cheapest,
simplest, or only realistic way to put an application online.

That constraint shapes the project. Features that are commonly built around newer
runtimes may look different here. For example, future edge functions are planned as
lightweight PHP scripts, even though similar systems often use Deno or other runtimes.
That choice is deliberate: on shared hosting, extra languages, background services,
containers, and process managers are often unavailable or impractical.

The goal is not to ignore modern infrastructure, but to make useful backend technology
available in environments that modern tooling often overlooks. Loxodontu is built for
open access: practical APIs, authentication, data management, and application backends
that can run where many people already are.

## Roadmap

This is where the project is heading — in rough priority order. Nothing here is a firm deadline, just an honest picture of what comes next.

### Now (in progress)

- **Management API** — auth (register/login/logout), projects, tables, API keys, and RLS policies are implemented.
- **REST passthrough** — per-project REST API via `pdo-restify`, with token-based auth and permission scopes (select/insert/update/delete).
- **RLS (Row-Level Security)** — column-value conditions per operation per table, managed via the API and enforced transparently at the REST layer. Policies can be scoped to an end-user role and reference the caller via `$auth.id` / `$auth.email` / `$auth.role` placeholders (e.g. "a manager can only update their own rows; an admin can do anything").
- **Project-level auth** — a project's own end users can register/login/logout (separate from platform users), authenticated via `X-User-Token` on REST passthrough requests. Roles are assigned by the project owner and feed RLS policies.
- **Schema alterations** — tables and columns can be changed after creation: rename a table, add a column, rename a column, change a column's type/nullability/default. Column removal is destructive and requires `?confirm=true`.
- **Edge functions** — project-scoped PHP functions exposed over HTTP and callable from cron jobs.
- **Cron jobs** — per-project scheduled jobs with retry, queues, recurring execution, and a single `worker.php` entry point for shared hosting cron integrations.
- **Dashboard UI** — web interface to manage projects, tables, columns, RLS policies, API keys, and end users without touching the API directly. PHP-routed pages, each backed by a scoped Vue 3 component.

### Next

### Later

- **Storage** — file upload and retrieval per project. Likely local filesystem first, with a path toward S3-compatible backends.
- **Schema history** — track column/table changes over time (who changed what, when), on top of the alteration endpoints that already exist.

### Someday (if it makes sense)

- **Realtime** — long-polling or SSE for table change events.
- **Edge functions** — lightweight PHP scripts executed per-request within project scope.

## API Overview

Everything lives under `/api/v1`. Platform routes (managing your own account, projects, tables,
keys, RLS policies, and end users) are authenticated with `Authorization: Bearer <platform token>`
from `/auth/login`. REST passthrough routes are authenticated with a project API key instead, plus
an optional end-user token — see below.

Every list endpoint below (tables, keys, RLS policies, end users) is paginated the same way REST
passthrough's own `GET` list endpoint is: `?limit=` (default 25, capped at 100) and `?offset=`
(default 0), with the result's total/limit/offset echoed back as `X-Total-Count`, `X-Page-Limit`,
and `X-Page-Offset` response headers.

### Platform auth

| Method | Route            | Auth | Description                  |
| ------ | ---------------- | ---- | ---------------------------- |
| POST   | `/auth/register` | —    | Create a platform account    |
| POST   | `/auth/login`    | —    | Get a platform token         |
| POST   | `/auth/logout`   | ✓    | Invalidate the current token |

### Projects

| Method | Route              | Description                          |
| ------ | ------------------ | ------------------------------------- |
| GET    | `/projects`        | List your projects                    |
| POST   | `/projects`        | Create a project (`{ name }`)         |
| GET    | `/projects/{id}`   | Get a project, with its tables        |
| DELETE | `/projects/{id}`   | Delete a project                      |

### Tables & schema alterations

| Method | Route                                        | Description                                                                 |
| ------ | --------------------------------------------- | ---------------------------------------------------------------------------- |
| GET    | `/projects/{id}/tables`                       | List tables, with their columns                                              |
| POST   | `/projects/{id}/tables`                       | Create a table (`{ name, columns: [...] }`)                                  |
| PATCH  | `/projects/{id}/tables/{table_id}`            | Rename a table (`{ name }`) — renames the physical table too                 |
| DELETE | `/projects/{id}/tables/{table_id}`            | Drop a table                                                                  |
| POST   | `/projects/{id}/tables/{table_id}/columns`    | Add a column (`{ name, type, nullable?, default_value? }`)                   |
| PATCH  | `/projects/{id}/tables/{table_id}/columns/{column_id}` | Rename a column and/or change its type/nullable/default (any subset) |
| DELETE | `/projects/{id}/tables/{table_id}/columns/{column_id}?confirm=true` | Drop a column — irreversible, so `?confirm=true` is required  |

Column `type` is one of `text`, `integer`, `decimal`, `boolean`, `timestamp`, `json`. A column can
never be named `id`, which is always the auto-incrementing primary key.

### API keys & RLS policies

| Method | Route                                                        | Description                                          |
| ------ | -------------------------------------------------------------| ------------------------------------------------------ |
| GET    | `/projects/{id}/keys`                                        | List a project's API keys                              |
| POST   | `/projects/{id}/keys`                                        | Create a key (`{ name, permissions: [...], expires_at? }`) |
| DELETE | `/projects/{id}/keys/{key_id}`                                | Revoke a key                                            |
| GET    | `/projects/{id}/tables/{table_id}/rls-policies`               | List a table's RLS policies                             |
| POST   | `/projects/{id}/tables/{table_id}/rls-policies`               | Create a policy (`{ name, operation, role?, conditions, enabled? }`) |
| DELETE | `/projects/{id}/tables/{table_id}/rls-policies/{policy_id}`   | Delete a policy                                         |

`permissions` is a subset of `select`, `insert`, `update`, `delete`. `operation` is one of `SELECT`,
`INSERT`, `UPDATE`, `DELETE`, `ALL`. `conditions` is a `column => value` object where a value can be
a literal or one of the placeholders `$auth.id`, `$auth.email`, `$auth.role`.

### End users (a project's own app users)

| Method | Route                                          | Auth              | Description                          |
| ------ | ------------------------------------------------| ------------------ | -------------------------------------- |
| POST   | `/{project_id}/auth/register`                   | —                  | Register an end user (`{ email, password }`) |
| POST   | `/{project_id}/auth/login`                      | —                  | Get an end-user token                  |
| POST   | `/{project_id}/auth/logout`                     | end-user token     | Invalidate the current end-user token  |
| GET    | `/projects/{id}/end-users`                      | platform token     | List a project's end users             |
| PATCH  | `/projects/{id}/end-users/{end_user_id}`        | platform token     | Grant/clear a role (`{ role }`, `null` clears it) |
| DELETE | `/projects/{id}/end-users/{end_user_id}`        | platform token     | Remove an end user                     |

### REST passthrough

| Method              | Route                              | Description                          |
| --------------------| -------------------------------------| --------------------------------------|
| GET/POST/PATCH/DELETE | `/{project_id}/rest/{table}`       | List/insert/bulk-update/bulk-delete   |
| GET/PATCH/PUT/DELETE  | `/{project_id}/rest/{table}/{id}`  | Get/update/delete a single row        |

Authenticated with `Authorization: Bearer <project API key>`, gated by that key's `permissions`.
Add `X-User-Token: <end-user token>` to authenticate as an end user for RLS purposes — omitting it
means an anonymous caller, for which any RLS condition referencing `$auth.*` never matches.

### Edge functions

Edge functions are project-scoped PHP callbacks exposed through the API. The name is intentionally
familiar, but the implementation is PHP-first: functions are autoloadable classes/methods running
inside the same application, which keeps the feature usable on shared hosting without Deno,
containers, or a separate function runtime.

The first implementation registers function metadata instead of accepting arbitrary PHP code from
the dashboard. That is deliberate. Running user-submitted PHP safely requires a stronger isolation
model than normal shared hosting can guarantee, so the current design favors explicit callbacks
that the project owner deploys with the application.

Each function has:

- `slug` — the public route segment.
- `handler` — a callable target using `ClassName::method`.
- `methods` — allowed HTTP methods. An empty list allows any supported method.
- `require_api_key` — whether external callers need a project API key with `function` permission.
- `enabled` — whether the function can be invoked.

Platform-authenticated management endpoints:

| Method | Route                                  | Description              |
| ------ | ---------------------------------------| ------------------------ |
| GET    | `/projects/{id}/functions`             | List project functions   |
| POST   | `/projects/{id}/functions`             | Register a function      |
| GET    | `/projects/{id}/functions/{function_id}` | Get a function         |
| PATCH  | `/projects/{id}/functions/{function_id}` | Update a function      |
| DELETE | `/projects/{id}/functions/{function_id}` | Delete a function      |

Invocation endpoint:

| Method | Route                         | Description              |
| ------ | ------------------------------| ------------------------ |
| ANY    | `/{project_id}/functions/{slug}` | Invoke a function      |

Example registration:

```bash
curl -X POST "$APP_URL/api/v1/projects/1/functions" \
  -H "Authorization: Bearer $PLATFORM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Daily cleanup",
    "slug": "daily-cleanup",
    "handler": "App\\Jobs\\DailyCleanup::handle",
    "methods": ["POST"],
    "require_api_key": true,
    "enabled": true
  }'
```

Example handler:

```php
namespace App\Jobs;

use App\Edge\FunctionRequest;
use App\Edge\FunctionResponse;

class DailyCleanup
{
    public static function handle(FunctionRequest $request): FunctionResponse
    {
        return FunctionResponse::json([
            'ok' => true,
            'project_id' => $request->projectId,
            'payload' => $request->body,
        ]);
    }
}
```

External invocation requires a project API key with the `function` permission when
`require_api_key` is enabled:

```bash
curl -X POST "$APP_URL/api/v1/1/functions/daily-cleanup" \
  -H "Authorization: Bearer $PROJECT_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{ "source": "client" }'
```

Cron jobs can call a function directly:

```json
{
  "name": "Daily cleanup",
  "type": "function",
  "target": "daily-cleanup",
  "queue": "maintenance",
  "interval_seconds": 86400,
  "payload": { "source": "cron" }
}
```

### Cron jobs

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
| ------ | -------------------------------------------| ---------------------------- |
| GET    | `/projects/{id}/cron-jobs`                 | List a project's cron jobs   |
| POST   | `/projects/{id}/cron-jobs`                 | Create a cron job            |
| GET    | `/projects/{id}/cron-jobs/{job_id}`        | Get a cron job               |
| PATCH  | `/projects/{id}/cron-jobs/{job_id}`        | Update a cron job            |
| DELETE | `/projects/{id}/cron-jobs/{job_id}`        | Delete a cron job            |
| GET    | `/projects/{id}/cron-jobs/{job_id}/runs`   | List execution history       |

Example recurring HTTP job:

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

Example PHP callback job:

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

Example function job:

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

## Testing

Feature tests run against a real MySQL/MariaDB database via [Pest](https://pestphp.com/). Set the
`DB_*_TEST` variables in `.env` (see `.env.example`), then:

```bash
composer test            # wipes + re-migrates the test DB, then runs the suite
composer test:coverage   # same, with a coverage report (requires Xdebug or PCOV)
```

CI (`.github/workflows/tests.yml`) runs the same suite on every push/PR against both MySQL and MariaDB.

## License

AGPL-3.0. See [LICENSE](LICENSE) and [COPYRIGHT](COPYRIGHT).
