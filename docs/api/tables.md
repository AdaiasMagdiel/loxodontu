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
