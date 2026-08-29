# API Overview

Everything lives under `/api/v1`. Platform routes (managing your own account, projects, tables,
keys, RLS policies, and end users) are authenticated with `Authorization: Bearer <platform token>`
from `/auth/login`. REST passthrough routes are authenticated with a project API key instead, plus
an optional end-user token — see [REST Passthrough](rest-passthrough.md).

Every list endpoint below (tables, keys, RLS policies, end users) is paginated the same way REST
passthrough's own `GET` list endpoint is: `?limit=` (default 25, capped at 100) and `?offset=`
(default 0), with the result's total/limit/offset echoed back as `X-Total-Count`, `X-Page-Limit`,
and `X-Page-Offset` response headers.
