# Tables & schema alterations

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

## Running raw SQL

| Method | Route                     | Description                        |
| ------ | -------------------------- | ------------------------------------ |
| POST   | `/projects/{id}/sql`       | Run arbitrary SQL against the project's tables (`{ query }`) |

This runs whatever SQL you send directly against the underlying database, authenticated with your
platform token — there is no query sanitization, statement whitelist, or RLS applied at this layer,
since it's meant as a platform-owner escape hatch (the dashboard's SQL runner uses this endpoint).
Treat the platform token like a database credential: anyone with it can read, write, or drop
anything in the project.
