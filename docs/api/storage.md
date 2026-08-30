# Storage

Storage organizes files into per-project **buckets**. Each object's metadata (path, size,
mime type, owner) is a row in `project_storage_objects`; the binary content is written to
local disk. Access control reuses the same RLS engine as [table RLS](keys-and-rls.md) —
buckets get **storage policies** with the same `operation` / `expression` shape (a raw SQL
boolean expression, see [keys-and-rls.md](keys-and-rls.md#expression-real-sql-not-a-condition-dsl)
for the full model), just scoped to a bucket instead of a table.

## Management (platform owner)

| Method | Route                                                              | Description                          |
| ------ | -------------------------------------------------------------------- | --------------------------------------|
| GET    | `/projects/{id}/storage/buckets`                                    | List a project's buckets              |
| POST   | `/projects/{id}/storage/buckets`                                    | Create a bucket (`{ name, public? }`) |
| PATCH  | `/projects/{id}/storage/buckets/{bucket_id}`                        | Toggle `public`                       |
| DELETE | `/projects/{id}/storage/buckets/{bucket_id}`                        | Delete a bucket (and its objects)     |
| GET    | `/projects/{id}/storage/buckets/{bucket_id}/policies`               | List a bucket's storage policies      |
| POST   | `/projects/{id}/storage/buckets/{bucket_id}/policies`               | Create a policy (`{ name, operation, expression, enabled? }`) |
| DELETE | `/projects/{id}/storage/buckets/{bucket_id}/policies/{policy_id}`   | Delete a policy                       |

`expression` references the fixed columns of an object: `id`, `bucket_id`, `path`, `owner_id`,
`size`, `mime_type`, `created_at`, `updated_at` — plus the usual `$auth.id` / `$auth.email` /
`$auth.role` placeholders. `owner_id` is always set by Storage itself to the uploading end
user (or `NULL` for an anonymous upload) — it's never client-choosable, so scoping to it is
reliable. A common pattern is scoping uploads/reads/deletes to the uploader:

```json
{ "operation": "ALL", "expression": "owner_id = $auth.id" }
```

On `INSERT`/`UPDATE` this is also the `WITH CHECK`: an upload whose (Storage-assigned)
`owner_id` doesn't satisfy the policy is rejected with `403` before the file ever touches
disk — e.g. an anonymous upload (`owner_id` is `NULL`) against the policy above, since SQL
never treats `NULL = NULL` as true.

## Object passthrough

| Method | Route                                              | Auth                          | Description                    |
| ------ | ----------------------------------------------------| -------------------------------| ---------------------------------|
| GET    | `/{project_id}/storage/public/{bucket}/{object_id}` | none                           | Download — only works if the bucket is `public` |
| GET    | `/{project_id}/storage/{bucket}`                    | API key (`storage:select`)    | List objects                   |
| POST   | `/{project_id}/storage/{bucket}`                    | API key (`storage:insert`)    | Upload (`multipart/form-data`, field `file`, optional field `path`) |
| GET    | `/{project_id}/storage/{bucket}/{object_id}`        | API key (`storage:select`)    | Download                       |
| PATCH  | `/{project_id}/storage/{bucket}/{object_id}`        | API key (`storage:update`)    | Rename (`{ "path": "..." }`)   |
| DELETE | `/{project_id}/storage/{bucket}/{object_id}`        | API key (`storage:delete`)    | Delete                         |

The list endpoint is paginated the same way as the management endpoints (`?limit=`, default
25, capped at 100; `?offset=`), echoing `X-Total-Count` / `X-Page-Limit` / `X-Page-Offset`.

Like REST passthrough, every route but the public download one is authenticated with
`Authorization: Bearer <project API key>` — the key needs the matching `storage:select` /
`storage:insert` / `storage:update` / `storage:delete` permission — plus an optional
`X-User-Token: <end-user token>` for RLS purposes. An object's `path` is a logical label
(e.g. `avatars/user-1.png`) unique per bucket; it is never used as a filesystem path, so it
may contain any characters, including `/`. Objects are addressed by numeric `id` in the URL
because the router doesn't support slashes inside a single route segment.

## Public vs. private buckets

A `public` bucket's objects are reachable at `/{project_id}/storage/public/{bucket}/{id}`
with no authentication at all — no key, no RLS check. Everything else (listing, uploading,
authenticated download, rename, delete) still goes through the usual key + policy gate
regardless of a bucket's `public` flag. Marking a bucket public only opens up serving its
files publicly, the way you'd point an `<img>` tag or a CDN at it.

## Storage location

Files are written under `STORAGE_PATH` (default: `storage/files` at the project root — see
[Configuration](../configuration.md)). Local filesystem only for now; an S3-compatible
backend is on the [roadmap](../roadmap.md).
