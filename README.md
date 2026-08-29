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
