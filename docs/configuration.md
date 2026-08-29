# Configuration

Loxodontu reads its configuration from a `.env` file at the project root (see `.env.example`),
loaded via [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv). Loading is non-fatal — if
`.env` is missing, the app still boots and falls back to whatever is already in the process
environment (useful when a host injects env vars directly rather than via a file).

Internally, `env()` checks `$_ENV` first, then falls back to `getenv()`, since some SAPIs and
PHP-FPM pools only populate one of the two — worth knowing if a variable "isn't picking up" on a
particular host.

## App

| Variable | Default | Description |
| -------- | ------- | ------------ |
| `ENV` | `development` | When set to `development`, enables verbose error display (`display_errors`, `E_ALL`) and makes the [error handler](api/errors.md) re-throw exceptions instead of returning a generic 500. Use anything else (e.g. `production`) in production. |
| `DEBUG` | `true` | When `true`, edge function "invalid response" errors include a debug block with the resolved PHP binary path. Turn off in production. |
| `APP_URL` | — | Not read anywhere by the app itself — it's a convention for your own scripts and the `curl` examples throughout this documentation. Set it to whatever base URL you're calling. |

## Database

| Variable | Description |
| -------- | ------------ |
| `DB_MODE` | Selects which suffixed variable set below is used: `development` → `_DEV`, `testing` → `_TEST`, anything else → `_PROD`. |
| `DB_HOST_DEV` / `DB_PORT_DEV` / `DB_USERNAME_DEV` / `DB_PASSWORD_DEV` / `DB_DATABASE_DEV` | Development database connection. |
| `DB_HOST_PROD` / `DB_PORT_PROD` / `DB_USERNAME_PROD` / `DB_PASSWORD_PROD` / `DB_DATABASE_PROD` | Production database connection. |
| `DB_HOST_TEST` / `DB_PORT_TEST` / `DB_USERNAME_TEST` / `DB_PASSWORD_TEST` / `DB_DATABASE_TEST` | Test database connection — the test suite forces `DB_MODE=testing` itself regardless of what's set here, so this is safe to leave configured alongside a dev/prod setup. See [Testing](testing.md). |

Only MySQL/MariaDB is supported end to end. The connection is lazy (no connection is opened until
the first query), and on MySQL/MariaDB the target database is created automatically on first
connect if it doesn't exist and the credentials have `CREATE DATABASE` privilege — table creation
still requires running migrations (see [Getting Started](getting-started.md)).

## Cron worker

| Variable | Default | Description |
| -------- | ------- | ------------ |
| `CRON_WORKER_TOKEN` | — | Optional shared secret required by `worker.php` (as `?token=` or `--token=`). Set this in production whenever `worker.php` is reachable from the web. |
| `CRON_WORKER_LIMIT` | `10` | How many due jobs a single worker invocation claims and executes before returning. Keep this small on shared hosting so each cron hit finishes quickly. |
| `CRON_ALLOW_COMMANDS` | `false` | Must be the literal string `true` to allow `command`-type cron jobs, which execute shell commands on the host. Leave disabled unless you trust every job creator. |

See [Cron Jobs](cron-jobs.md) for how the worker is invoked and how job types work.

## Edge functions

| Variable | Description |
| -------- | ------------ |
| `EDGE_PHP_BINARY` | Overrides the CLI PHP binary used to spawn the sandboxed subprocess that runs edge function code. Set this on shared hosts where `PHP_BINARY` resolves to `lsphp` or `php-cgi`, which can't run as a plain CLI interpreter. |

See [Edge Functions](edge-functions.md) for the sandbox model this feeds into.

## Storage

| Variable | Default | Description |
| -------- | ------- | ------------ |
| `STORAGE_PATH` | `storage/files` (project root) | Absolute path where uploaded files are written to disk, one subdirectory per project and bucket. |

See [Storage](api/storage.md) for buckets, policies, and the upload/download API.
