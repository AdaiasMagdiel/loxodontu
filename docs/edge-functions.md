# Edge functions

Edge functions are project-scoped PHP functions exposed through the API. The name is intentionally
familiar, but the implementation is PHP-first: functions run as PHP source code managed by the
project, which keeps the feature usable on shared hosting without Deno, containers, or a separate
function runtime.

Functions can be created from the dashboard by writing or pasting PHP code, or by uploading a PHP
file. The UI generates the `slug` from the function name while you type, and the slug can still be
edited before saving.

User-provided function source is executed in a separate PHP process with a short timeout, memory
limit, an isolated temporary runtime, `open_basedir` restricted to the function sandbox/runtime
files, `allow_url_fopen` disabled, and common dangerous functions disabled. This is a pragmatic
shared-hosting sandbox, not the same security boundary as a container, VM, or dedicated isolate. It is
designed to reduce filesystem/path discovery and host access while keeping the feature deployable in
plain PHP environments.

## Database access

`$request->db` gives function code a way to read and write the project's own tables without
ever handing it a database connection. The sandbox has no PDO and no credentials — every call
on `$request->db` is sent over a pipe to the trusted parent process, which resolves the table
against *this project only* and applies RLS exactly as REST passthrough does (same policies,
same `$auth.*` placeholders, same permissive-OR semantics — see [API keys & RLS
policies](api/keys-and-rls.md)). A function can never see another project's tables, and rows a
policy hides from the calling end user stay hidden here too.

```php
<?php

use App\Edge\FunctionRequest;
use App\Edge\FunctionResponse;

return function (FunctionRequest $request): FunctionResponse {
    $todo = $request->db->table('todos')->insert([
        'title'      => $request->body['title'] ?? 'Untitled',
        'created_by' => $request->auth['id'] ?? null,
    ]);

    if (!$todo->ok) {
        return FunctionResponse::json(['error' => $todo->body], $todo->status);
    }

    $open = $request->db->table('todos')
        ->select('id,title')
        ->where('done', 'eq', 'false')
        ->order('id', 'desc')
        ->limit(10)
        ->get();

    return FunctionResponse::json([
        'created' => $todo->body,
        'open'    => $open->body,
    ]);
};
```

`table($name)` returns a query builder:

- `select($columns = '*')` — restrict the returned columns (`'id,title'`).
- `where($column, $operator, $value)` — one filter per call; operators match REST passthrough's
  (`eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `is_null`, ...).
- `order($column, $direction = 'asc')`, `limit($n)`, `offset($n)`.
- `id($value)` — target a single row by primary key for `first()`, `update()`, or `delete()`.
- `get()` / `first()` — run a `select`.
- `insert($data)`, `update($data)`, `delete()` — write operations, gated by that table's RLS
  policies exactly like a REST request from the calling end user would be.
- `insertMany($rows)`, `updateMany($rows)` — insert or update a list of rows in one call, in a
  single transaction (if any row fails, none of them are persisted). Each row passed to
  `updateMany()` must include the table's primary key column.
- `orWhere($column, $operator, $value)` — adds one leg of a single OR group (every `orWhere()`
  call on a query joins the same group), itself AND'ed with every `where()` filter and the
  table's RLS policy: `where('published', 'eq', 'true')->orWhere('status', 'eq', 'draft')
  ->orWhere('status', 'eq', 'pending')` reads as `published = true AND (status = 'draft' OR
  status = 'pending')`.

Every call returns a `DbResult` with `ok` (bool), `status` (HTTP-style status code), `body`
(the row/rows on success, an error payload otherwise), and `error`.

Calling `where()` more than once for the same column adds an additional condition on it rather
than replacing the previous one, so range queries work as expected:
`where('age', 'gte', 18)->where('age', 'lte', 65)`. A literal comma inside an `in`/`not_in` value
is escaped automatically, so `where('tag', 'in', ['a,b', 'c'])` matches a `tag` of exactly `a,b`
or `c`, not four separate values.

A call can wait up to 20 seconds for the platform's response before it throws — this bounds the
DB bridge independently of (and below) the function's own `timeout_seconds`, so a stuck bridge
fails with a clear error instead of hanging the sandbox until the outer timeout fires.

Each function runs with whatever end-user identity was resolved for the invocation — the same
`$auth` available as `$request->auth`, populated from the `X-User-Token` header when
`require_api_key` is enabled. An invocation with no resolved user runs the database bridge as
anonymous, so owner-scoped policies exclude it the same way they exclude an anonymous REST
caller.

Each function has:

- `slug` — the public route segment.
- `source_code` — the PHP source for the function.
- `methods` — allowed HTTP methods. An empty list allows any supported method.
- `require_api_key` — whether external callers need a project API key with `function` permission.
- `enabled` — whether the function can be invoked.
- `timeout_seconds` and `memory_limit_mb` — per-invocation safety limits.

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

## Example registration

```bash
curl -X POST "$APP_URL/api/v1/projects/1/functions" \
  -H "Authorization: Bearer $PLATFORM_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Daily cleanup",
    "slug": "daily-cleanup",
    "source_code": "<?php\n\nuse App\\Edge\\FunctionRequest;\nuse App\\Edge\\FunctionResponse;\n\nreturn function (FunctionRequest $request): FunctionResponse {\n    return FunctionResponse::json([\"ok\" => true, \"payload\" => $request->body]);\n};\n",
    "methods": ["POST"],
    "require_api_key": true,
    "enabled": true,
    "timeout_seconds": 10,
    "memory_limit_mb": 32
  }'
```

## Example function source

```php
<?php

use App\Edge\FunctionRequest;
use App\Edge\FunctionResponse;
use App\Edge\Http;

return function (FunctionRequest $request): FunctionResponse {
    $result = Http::get('https://example.com');

    return FunctionResponse::json([
        'ok' => true,
        'project_id' => $request->projectId,
        'payload' => $request->body,
        'external_status' => $result['status'],
    ]);
};
```

Use `App\Edge\Http` for outbound HTTP calls. Direct filesystem URL wrappers such as
`file_get_contents('https://example.com')` are disabled inside the sandbox.

Only `http`/`https` URLs are allowed, and both the target host and every address it resolves to
(IPv4 and IPv6) must be public — private, loopback, link-local, and reserved ranges are rejected.
The resolved IP is pinned to the connection, so a host that resolves to a different address
between validation and connection (DNS rebinding) can't be used to slip past that check.
Redirects are followed up to 5 hops, re-validating and re-pinning each target the same way, so a
redirect to a private address is rejected exactly like a direct request to one would be; an
`Authorization` header is dropped across a redirect that changes host. Responses larger than 5 MB
(per the `Content-Length` header) are aborted rather than buffered into memory.

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
