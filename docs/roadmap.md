# Roadmap

This is where the project is heading — in rough priority order. Nothing here is a firm
deadline, just an honest picture of what comes next.

## Now (in progress)

- **Management API** — auth (register/login/logout), projects, tables, API keys, and RLS policies are implemented.
- **REST passthrough** — per-project REST API via `pdo-restify`, with token-based auth and permission scopes (select/insert/update/delete).
- **RLS (Row-Level Security)** — policies are raw SQL boolean expressions per operation per table (real `WHERE`/`WITH CHECK` power — `OR`, `IN (...)`, column-to-column comparisons), managed via the API and enforced transparently at the REST layer via `pdo-restify`'s `RawCondition`. Multiple policies for the same operation are OR'd together; expressions reference the caller via `$auth.id` / `$auth.email` / `$auth.role` placeholders (e.g. "a manager can only update their own rows; an admin can do anything").
- **Project-level auth** — a project's own end users can register/login/logout (separate from platform users), authenticated via `X-User-Token` on REST passthrough requests. Roles are assigned by the project owner and feed RLS policies.
- **Schema alterations** — tables and columns can be changed after creation: rename a table, add a column, rename a column, change a column's type/nullability/default. Column removal is destructive and requires `?confirm=true`.
- **Edge functions** — project-scoped PHP functions exposed over HTTP and callable from cron jobs.
- **Cron jobs** — per-project scheduled jobs with retry, queues, recurring execution, and a single `worker.php` entry point for shared hosting cron integrations.
- **Dashboard UI** — web interface to manage projects, tables, columns, RLS policies, API keys, and end users without touching the API directly. PHP-routed pages, each backed by a scoped Vue 3 component.
- **Storage** — file upload and retrieval per project, organized into buckets. Object metadata lives in the database; access control reuses the same RLS engine as tables (storage policies), scoped per bucket. Local filesystem only for now.

## Next

## Later

- **Storage: S3-compatible backend** — an alternative to local filesystem storage for buckets.
- **Schema history** — track column/table changes over time (who changed what, when), on top of the alteration endpoints that already exist.

## Someday (if it makes sense)

- **Realtime** — long-polling or SSE for table change events.
