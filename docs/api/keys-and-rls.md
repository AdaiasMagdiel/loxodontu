# API keys & RLS policies

| Method | Route                                                        | Description                                          |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| GET    | `/projects/{id}/keys`                                        | List a project's API keys                              |
| POST   | `/projects/{id}/keys`                                        | Create a key (`{ name, permissions: [...], expires_at? }`) |
| DELETE | `/projects/{id}/keys/{key_id}`                                | Revoke a key                                            |
| GET    | `/projects/{id}/tables/{table_id}/rls-policies`               | List a table's RLS policies                             |
| POST   | `/projects/{id}/tables/{table_id}/rls-policies`               | Create a policy (`{ name, operation, expression, enabled? }`) |
| DELETE | `/projects/{id}/tables/{table_id}/rls-policies/{policy_id}`   | Delete a policy                                         |

`permissions` is a subset of `select`, `insert`, `update`, `delete`. `operation` is one of `SELECT`,
`INSERT`, `UPDATE`, `DELETE`, `ALL`.

## `expression`: real SQL, not a condition DSL

`expression` is a raw SQL boolean expression evaluated against the table's own columns —
`OR`, `IN (...)`, comparisons between columns, whatever your database's `WHERE` clause
accepts.

### `$auth.id` / `$auth.email` / `$auth.role`

These three are **not columns of your table** — they're a fixed set of placeholders the
API substitutes for the identity of the caller, i.e. the *end user* authenticated via
`X-User-Token` (a row in `project_end_users`, registered through `POST
/{project_id}/auth/register`, separate from your own platform login). Concretely:

- `$auth.id` → that end user's numeric id.
- `$auth.email` → their email.
- `$auth.role` → the free-text `role` you optionally assign them via `PATCH
  /projects/{id}/end-users/{end_user_id}` (`null` until you set one).

They're substituted as bound parameters, never string-concatenated. For an anonymous
caller (no `X-User-Token` sent, or an invalid/expired one) all three resolve to `NULL` —
and since `column = NULL` is never true in SQL, an ownership-style policy like `created_by
= $auth.id` excludes anonymous callers with no extra logic needed.

`$auth.role` is entirely optional — if your app has no notion of roles, just never
reference it. A policy like `created_by = $auth.id` (no role check at all) is perfectly
normal and is exactly what "each end user only sees/edits what they created" looks like.
Roles only matter once you want *different* end users to have different levels of access
(e.g. "a manager can only touch their own rows, an admin can touch anyone's").

```json
{ "name": "owner or admin", "operation": "ALL", "expression": "created_by = $auth.id OR $auth.role = 'admin'" }
```

Multiple **enabled** policies registered for the same operation (including an `ALL`
policy, which applies to all four) are combined with `OR` — the same permissive-policy
semantics Postgres RLS uses. A table with no policies at all for an operation is fully
open for that operation (the pre-RLS default); a policy that never matches a given
caller/row simply filters it out, rather than returning an error.

- **On `SELECT`/`UPDATE`/`DELETE`**, the expression scopes which rows are visible/touchable
  (Postgres calls this `USING`) — merged into the query's `WHERE`.
- **On `INSERT`, and again on `UPDATE`**, the expression is also a **`WITH CHECK`**: after
  the write runs (with the client's submitted values, through the column whitelist), it's
  re-evaluated against the resulting row. A violation rolls the write back and responds
  `403` — a client that submits `created_by` other than their own id is **rejected outright**,
  not silently corrected to the right value. Design your policy expressions with this in
  mind: a client must supply values that already satisfy the policy, the same way a real
  Postgres `WITH CHECK` constraint works.

Since policies are written by the platform owner via this management API — the same
person who already has unrestricted raw SQL access through the SQL Editor — `expression`
is trusted the same way: not parsed or whitelisted beyond a few cheap guardrails (must be
non-empty, under 10,000 characters, no `;`/`--`/`/*`, and only the three documented
`$auth.*` placeholders), which exist to catch mistakes with a friendly `422`, not to
sandbox untrusted input.
