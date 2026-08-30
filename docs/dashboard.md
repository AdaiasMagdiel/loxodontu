# Dashboard

Loxodontu ships a web dashboard for managing projects, tables, RLS policies, API keys, storage
buckets and files, edge functions, cron jobs, and end users without touching the API directly.

## Architecture

Each dashboard route is a normal, server-rendered PHP page — navigating between dashboard pages is
plain browser navigation, not a client-side router. What makes a page interactive is that it mounts
a single scoped Vue 3 component, which handles all data fetching, forms, and updates on that page
by calling the same JSON API documented throughout these pages, authenticated with the platform
token that's stored client-side (there is no server-side session).

In other words: the dashboard is a thin, server-routed shell around the public API — everything it
can do, your own application can also do directly over HTTP.

## Pages

| Route | Purpose |
| ----- | -------- |
| `/dashboard` | Home |
| `/dashboard/account` | Manage the authenticated platform account |
| `/dashboard/projects` | List projects |
| `/dashboard/projects/{project_id}` | Project overview |
| `/dashboard/projects/{project_id}/tables` | Manage tables and columns |
| `/dashboard/projects/{project_id}/sql` | Run raw SQL against the project's tables — see [Running raw SQL](api/tables.md#running-raw-sql) |
| `/dashboard/projects/{project_id}/keys` | Manage project API keys |
| `/dashboard/projects/{project_id}/storage` | Manage storage buckets, browse/upload/download/delete files, and manage storage policies |
| `/dashboard/projects/{project_id}/functions` | Manage edge functions |
| `/dashboard/projects/{project_id}/cron-jobs` | Manage cron jobs |
| `/dashboard/projects/{project_id}/end-users` | Manage a project's end users and roles |

None of these routes require a separate auth mechanism from the API itself — you log in once, and
the platform token drives every request the dashboard makes on your behalf.
