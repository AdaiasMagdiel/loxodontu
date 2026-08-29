# Clients

Talking to Loxodontu's API directly with `fetch`/`curl` works fine, but a client library saves
you from re-implementing auth headers, pagination, and filter query strings by hand.

## JavaScript / TypeScript

[`@adaiasmagdiel/loxodontu`](https://www.npmjs.com/package/@adaiasmagdiel/loxodontu)
([source](https://github.com/adaiasmagdiel/loxodontu-js)) is the official JS/TS client. It works
from npm (ESM/CJS, fully typed) or as a single `<script>` tag in the browser — no bundler, no
build step.

```bash
npm install @adaiasmagdiel/loxodontu
```

It mirrors the API's two token types with two clients:

- **`createClient`** — app-facing. REST passthrough, edge function invocation, and end-user auth
  for a single project, authenticated with a project API key. See [Authentication &
  Tokens](api/authentication.md) and [REST Passthrough](api/rest-passthrough.md).
- **`createAdminClient`** — platform-facing. Manage your account, projects, tables, keys, RLS
  policies, cron jobs, and functions, authenticated with your platform login. See [Platform
  Auth](api/platform-auth.md).

```ts
import { createClient } from "@adaiasmagdiel/loxodontu";

const loxo = createClient(
  "https://your-app.example.com/api/v1",
  "my-project", // project id / slug
  "PROJECT_API_KEY",
);

// Query builder is awaitable directly — no .execute() needed.
const { data: todos, error } = await loxo
  .from("todos")
  .select()
  .eq("done", false)
  .order("created_at", { ascending: false })
  .limit(20);

await loxo.from("todos").insert({ title: "Write docs" });

// End users (your app's own users, separate from your platform account)
await loxo.auth.login("user@example.com", "password123");

// Edge functions
await loxo.functions.invoke("daily-cleanup", { body: { source: "client" } });
```

```ts
import { createAdminClient } from "@adaiasmagdiel/loxodontu";

const admin = createAdminClient("https://your-app.example.com/api/v1");
await admin.auth.login("me@example.com", "password123");

const { data: project } = await admin.projects.create({ name: "New project" });
await admin.projects.for(project.id).tables.create({
  name: "todos",
  columns: [{ name: "title", type: "text" }, { name: "done", type: "boolean", default_value: false }],
});
```

Every call resolves to the same envelope instead of throwing by default — `{ data, error, count,
status }` — matching the "check `error`" pattern of most BaaS clients; wrap a call in
`LoxodontuError` if you'd rather throw on failure. Filters (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`,
`like`, `in`) mirror REST passthrough's query parameters 1:1. Session tokens persist via
`localStorage` when available (override with your own `storage`), and `fetch` is used directly, so
Node 18+ works with no polyfill. See the [package README](https://github.com/adaiasmagdiel/loxodontu-js)
for the full API.

## Other languages

No official client yet for PHP, Python, or other languages — the [REST
API](api/overview.md) is plain HTTP/JSON, so any HTTP client works in the meantime.
