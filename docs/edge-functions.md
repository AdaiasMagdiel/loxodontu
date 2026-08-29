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
