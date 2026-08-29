# API Overview

The HTTP layer — routing, requests, responses, middleware — is
[Erlenmeyer](https://github.com/adaiasmagdiel/erlenmeyer), a small standalone PHP framework.

Everything lives under `/api/v1`. Platform routes (managing your own account, projects, tables,
keys, RLS policies, and end users) are authenticated with `Authorization: Bearer <platform token>`
from `/auth/login`. REST passthrough routes are authenticated with a project API key instead, plus
an optional end-user token — see [REST Passthrough](rest-passthrough.md).

Every list endpoint below (tables, keys, RLS policies, end users) is paginated with `?limit=`
(default 25, capped at 100) and `?offset=` (default 0), with the result's total/limit/offset
echoed back as `X-Total-Count`, `X-Page-Limit`, and `X-Page-Offset` response headers — the same
mechanics as REST passthrough's own `GET` list endpoint, except passthrough defaults `limit` to
50 instead of 25 when it's omitted.

For request/response formatting, HTTP status codes, and how each auth token is structured and
expires, see [Authentication & Tokens](authentication.md) and [Errors & Responses](errors.md).

## Health check

| Method | Route         | Auth | Description                        |
| ------ | ------------- | ---- | ------------------------------------ |
| GET    | `/api/health` | —    | Returns `{ "status": "ok" }` — useful for uptime checks and load balancer probes. |

Note this route is outside `/api/v1`.
