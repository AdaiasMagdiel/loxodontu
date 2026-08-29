# End users

A project's own app users, separate from platform accounts.

| Method | Route                                          | Auth              | Description                          |
| ------ | ------------------------------------------------| ------------------ | -------------------------------------- |
| POST   | `/{project_id}/auth/register`                   | —                  | Register an end user (`{ email, password }`) |
| POST   | `/{project_id}/auth/login`                      | —                  | Get an end-user token                  |
| POST   | `/{project_id}/auth/logout`                     | end-user token     | Invalidate the current end-user token  |
| GET    | `/projects/{id}/end-users`                      | platform token     | List a project's end users             |
| PATCH  | `/projects/{id}/end-users/{end_user_id}`        | platform token     | Grant/clear a role (`{ role }`, `null` clears it) |
| DELETE | `/projects/{id}/end-users/{end_user_id}`        | platform token     | Remove an end user                     |
