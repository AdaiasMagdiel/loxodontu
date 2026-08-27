# Loxodontu

Loxodontu is an open-source Backend-as-a-Service (BaaS) built with modern PHP. Designed as
a lightweight, self-hostable alternative to Supabase and Firebase, it provides developers
with instant APIs, database management, and essential backend infrastructure right out of
the box—leveraging the speed, simplicity, and ecosystem of PHP 8+.

Early development. Just started, no stable release yet, expect things to change.

## Roadmap

This is where the project is heading — in rough priority order. Nothing here is a firm deadline, just an honest picture of what comes next.

### Now (in progress)

- **Management API** — auth (register/login/logout), projects, tables, and API keys are implemented.
- **REST passthrough** — per-project REST API via `pdo-restify`, with token-based auth and permission scopes (select/insert/update/delete).

### Next

- **RLS (Row-Level Security)** — define column-value conditions per operation per table; enforced transparently at the REST layer.
- **Dashboard UI** — minimal web interface to manage projects, tables, columns, and API keys without touching the API directly.

### Later

- **Storage** — file upload and retrieval per project. Likely local filesystem first, with a path toward S3-compatible backends.
- **Project-level auth** — let end users of a project register and authenticate, not just platform users. JWT-based, isolated per project.
- **Migrations / schema history** — track column changes over time, non-destructive alterations.

### Someday (if it makes sense)

- **Realtime** — long-polling or SSE for table change events.
- **Edge functions** — lightweight PHP scripts executed per-request within project scope.

## License

AGPL-3.0. See [LICENSE](LICENSE) and [COPYRIGHT](COPYRIGHT).
