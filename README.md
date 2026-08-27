# Loxodontu

Loxodontu is an open-source Backend-as-a-Service (BaaS) built with modern PHP. Designed as
a lightweight, self-hostable alternative to Supabase and Firebase, it provides developers
with instant APIs, database management, and essential backend infrastructure right out of
the box—leveraging the speed, simplicity, and ecosystem of PHP 8+.

Early development. Just started, no stable release yet, expect things to change.

## Roadmap

This is where the project is heading — in rough priority order. Nothing here is a firm deadline, just an honest picture of what comes next.

### Now (in progress)

- **Management API** — auth (register/login/logout), projects, tables, API keys, and RLS policies are implemented.
- **REST passthrough** — per-project REST API via `pdo-restify`, with token-based auth and permission scopes (select/insert/update/delete).
- **RLS (Row-Level Security)** — column-value conditions per operation per table, managed via the API and enforced transparently at the REST layer. Policies can be scoped to an end-user role and reference the caller via `$auth.id` / `$auth.email` / `$auth.role` placeholders (e.g. "a manager can only update their own rows; an admin can do anything").
- **Project-level auth** — a project's own end users can register/login/logout (separate from platform users), authenticated via `X-User-Token` on REST passthrough requests. Roles are assigned by the project owner and feed RLS policies.

### Next

- **Dashboard UI** — minimal web interface to manage projects, tables, columns, and API keys without touching the API directly.

### Later

- **Storage** — file upload and retrieval per project. Likely local filesystem first, with a path toward S3-compatible backends.
- **Migrations / schema history** — track column changes over time, non-destructive alterations.

### Someday (if it makes sense)

- **Realtime** — long-polling or SSE for table change events.
- **Edge functions** — lightweight PHP scripts executed per-request within project scope.

## Testing

Feature tests run against a real MySQL/MariaDB database via [Pest](https://pestphp.com/). Set the
`DB_*_TEST` variables in `.env` (see `.env.example`), then:

```bash
composer test            # wipes + re-migrates the test DB, then runs the suite
composer test:coverage   # same, with a coverage report (requires Xdebug or PCOV)
```

CI (`.github/workflows/tests.yml`) runs the same suite on every push/PR against both MySQL and MariaDB.

## License

AGPL-3.0. See [LICENSE](LICENSE) and [COPYRIGHT](COPYRIGHT).
