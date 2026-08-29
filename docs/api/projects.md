# Projects

| Method | Route              | Description                                          |
| ------ | ------------------ | ------------------------------------------------------ |
| GET    | `/projects`        | List your projects                                    |
| POST   | `/projects`        | Create a project (`{ name }`)                         |
| GET    | `/projects/{id}`   | Get a project, with its tables                        |
| PATCH  | `/projects/{id}`   | Update a project (`{ name?, description? }`, partial) |
| DELETE | `/projects/{id}`   | Delete a project                                       |
