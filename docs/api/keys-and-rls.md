# API keys & RLS policies

| Method | Route                                                        | Description                                          |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| GET    | `/projects/{id}/keys`                                        | List a project's API keys                              |
| POST   | `/projects/{id}/keys`                                        | Create a key (`{ name, permissions: [...], expires_at? }`) |
| DELETE | `/projects/{id}/keys/{key_id}`                                | Revoke a key                                            |
| GET    | `/projects/{id}/tables/{table_id}/rls-policies`               | List a table's RLS policies                             |
| POST   | `/projects/{id}/tables/{table_id}/rls-policies`               | Create a policy (`{ name, operation, role?, conditions, enabled? }`) |
| DELETE | `/projects/{id}/tables/{table_id}/rls-policies/{policy_id}`   | Delete a policy                                         |

`permissions` is a subset of `select`, `insert`, `update`, `delete`. `operation` is one of `SELECT`,
`INSERT`, `UPDATE`, `DELETE`, `ALL`. `conditions` is a `column => value` object where a value can be
a literal or one of the placeholders `$auth.id`, `$auth.email`, `$auth.role`.
