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

## `expression`: a restricted SQL boolean expression

`expression` is a SQL boolean expression evaluated against the table's own columns — `AND`/`OR`/`NOT`,
`IN (...)`, `LIKE`, `BETWEEN`, `IS [NOT] NULL`, `CASE`, comparisons between columns and literals.

Because policies run against the shared database, an expression may only use:

- the **columns of its own table** (plain or backtick-quoted names — no `table.column`);
- the `$auth.id` / `$auth.email` / `$auth.role` placeholders (below);
- string and number literals, operators and parentheses;
- a fixed set of pure functions: `LOWER`, `UPPER`, `TRIM`, `LENGTH`, `CHAR_LENGTH`, `CONCAT`,
  `SUBSTRING`, `LEFT`, `RIGHT`, `LOCATE`, `REPLACE`, `FIND_IN_SET`, `COALESCE`, `IFNULL`, `NULLIF`,
  `IF`, `GREATEST`, `LEAST`, `ABS`, `ROUND`, `FLOOR`, `CEIL`, `NOW`, `CURDATE`, `UTC_TIMESTAMP`,
  `DATE`, `DATE_ADD`, `DATE_SUB`, `DATEDIFF`, `TIMESTAMPDIFF`, `JSON_CONTAINS`, `JSON_EXTRACT`,
  `JSON_UNQUOTE`, `JSON_LENGTH` (and a few aliases), plus `INTERVAL n DAY`-style intervals.

Subqueries, other tables, `@variables`, comments, `;` and any other function are rejected with a
`422` that names the offending part.

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

Expressions are validated when a policy is created **and again every time it is applied**. A stored
policy that no longer passes validation (for example one created before these rules existed, or one
that references a column that was later renamed) fails closed: it matches no rows instead of being
run.
