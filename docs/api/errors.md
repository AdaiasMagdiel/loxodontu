# Errors & Responses

## Success responses

There's no response envelope — endpoints return the resource itself directly, as a JSON object or
array:

- `POST`/`GET` (single resource) → the resource object, `201` on create.
- `GET` (list) → a bare JSON array. Pagination totals are in headers, not the body — see
  [Pagination headers](#pagination-headers) below.
- `DELETE` / logout → `204 No Content`, empty body.

## Error responses

Most errors — anything raised directly by a controller — come back as:

```json
{ "error": "Descriptive message here" }
```

with an appropriate status code: `401` (missing/invalid/expired token), `403` (authenticated but
not permitted), `404` (resource not found), `405` (method not allowed), `409` (conflict, e.g.
duplicate email or slug), or `422` (validation failure).

**404s and unhandled exceptions use a different shape**, from the app's central error handler
rather than a controller:

```json
{ "status": "error", "message": "Not Found" }
```

```json
{ "status": "error", "message": "Internal Server Error" }
```

This is a real inconsistency in the current API (`{ "error": ... }` vs. `{ "status", "message" }`)
rather than a documentation simplification — if you're writing a client, handle both shapes rather
than assuming `error` is always present. In development (`ENV=development`), unhandled exceptions
are re-thrown instead of returning a generic 500, so you see the real PHP error and stack trace.

## Common validation messages (422)

Non-exhaustive, but covers what you'll hit most:

- `name must be a non-empty string` / similar per-field required-field messages
- `Email already registered` *(409, not 422 — included here since it's the most common signup error)*
- `password must be at least 8 characters`
- `permissions must contain only: select, insert, update, delete, function, storage:select, storage:insert, storage:update, storage:delete`
- `expires_at must be a future datetime`
- `slug must contain only letters, numbers, dashes, and underscores`
- `Function slug already exists in this project` *(409)*
- `source_code must be a PHP file starting with <?php`
- `methods must contain only: GET, POST, PUT, PATCH, DELETE` (or similar, per allowed method list)
- `timeout_seconds must be between 1 and 60`
- `memory_limit_mb must be between 16 and 256`
- `handler must use ClassName::method syntax`
- `role must be null or an alphanumeric string (max 64 chars)`
- `expression is required` / `expression is too long (max 10000 characters)` — RLS/storage policies
- `expression must be a single boolean expression: ";", "--" and "/*" are not allowed`
- `unknown placeholder $auth.foo; use one of: $auth.id, $auth.email, $auth.role`
- `Nothing to update` — returned by `PATCH` endpoints when the request body has no recognized
  fields to change

## Pagination headers

Every paginated list endpoint (tables, keys, RLS policies, end users, cron jobs, functions,
storage buckets/objects/policies, and REST/Storage passthrough's own list endpoints) echoes the
resolved pagination back as response headers:

| Header | Meaning |
| ------ | -------- |
| `X-Total-Count` | Total matching rows, ignoring `limit`/`offset` |
| `X-Page-Limit` | The `limit` actually applied (default 25 for platform list endpoints, 50 for REST passthrough; capped at 100 either way) |
| `X-Page-Offset` | The `offset` actually applied (default 0) |
