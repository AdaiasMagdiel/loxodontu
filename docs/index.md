![Loxodontu](assets/banner-loxodontu.webp)

Loxodontu is an open-source Backend-as-a-Service (BaaS) built with modern PHP. Designed as
a lightweight, self-hostable alternative to Supabase and Firebase, it provides developers
with instant APIs, database management, and essential backend infrastructure right out of
the box — leveraging the speed, simplicity, and ecosystem of PHP 8+.

!!! warning "Early development"
    Just started, no stable release yet, expect things to change.

## What you get

- **Management API** — auth, projects, tables, API keys, and RLS policies.
- **REST passthrough** — an instant, token-authenticated REST API in front of your tables.
- **Row-Level Security (RLS)** — policies are raw SQL boolean expressions (real `WHERE`/`WITH CHECK` power, not a fixed condition DSL), enforced transparently at the REST layer.
- **Storage** — per-project file buckets with the same RLS-style policies, upload/download/delete via the API or the dashboard's file browser.
- **Project-level auth** — a project's own end users, separate from platform accounts.
- **Edge functions** — project-scoped PHP functions exposed over HTTP, sandboxed for shared hosting.
- **Cron jobs** — scheduled, retryable jobs driven by a single `worker.php` entry point.

**New here?** Start with [Getting Started](getting-started.md) to run Loxodontu locally in a few
minutes. Read [Philosophy](philosophy.md) for why the project is built this way, or jump straight
into the [API Overview](api/overview.md). Deploying to shared hosting? See
[Deployment](deployment.md). Building a frontend? See [Clients](clients.md) for the official
JS/TS SDK.

## Built with

Loxodontu's core is three small, independent PHP libraries, also open-source:

- **[Erlenmeyer](https://github.com/adaiasmagdiel/erlenmeyer)** — the HTTP layer: routing,
  requests, responses, and middleware.
- **[pdo-restify](https://github.com/adaiasmagdiel/pdo-restify)** — turns a PDO connection into a
  REST API; powers [REST passthrough](api/rest-passthrough.md).
- **[fullcrawl](https://github.com/adaiasmagdiel/fullcrawl)** — the migration runner used to
  manage the database schema (see [Getting Started](getting-started.md#run-the-migrations)).

Each one is usable on its own, outside of Loxodontu, in any PHP project.

## Clients

- **[@adaiasmagdiel/loxodontu](https://www.npmjs.com/package/@adaiasmagdiel/loxodontu)**
  ([source](https://github.com/adaiasmagdiel/loxodontu-js)) — the official JavaScript/TypeScript
  client, for both browsers and Node. See [Clients](clients.md).

## License

AGPL-3.0. See [LICENSE](https://github.com/adaiasmagdiel/loxodontu/blob/main/LICENSE) and
[COPYRIGHT](https://github.com/adaiasmagdiel/loxodontu/blob/main/COPYRIGHT).
