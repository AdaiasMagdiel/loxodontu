# REST passthrough

This layer is powered by [pdo-restify](https://github.com/adaiasmagdiel/pdo-restify), which turns
a PDO connection into a REST API — RLS and auth are applied on top of it.

| Method              | Route                              | Description                          |
| --------------------| -------------------------------------| --------------------------------------|
| GET/POST/PATCH/DELETE | `/{project_id}/rest/{table}`       | List/insert/bulk-update/bulk-delete   |
| GET/PATCH/PUT/DELETE  | `/{project_id}/rest/{table}/{id}`  | Get/update/delete a single row        |

Authenticated with `Authorization: Bearer <project API key>`, gated by that key's `permissions`.
Add `X-User-Token: <end-user token>` to authenticate as an end user for RLS purposes — omitting it
means an anonymous caller, for which any RLS condition referencing `$auth.*` never matches.
