# Loxodontu UI — Target Design Spec (Market-Level)

This is **not** a description of what exists today. It's the target: what the dashboard
should become to read like a real product (Supabase/Vercel/PlanetScale-tier), given the
stack constraints below. Build new/redesigned pages against this doc, not against the
current templates — the current templates are the migration source, not the reference.

The core diagnosis driving this doc: today, each top-level concern is one dense page that
accordions everything inside it (Storage = buckets+objects+policies in one expandable list;
Auth = provider+toggle+templates in one scroll). Market-level tools instead **drill down**:
a list page → a dedicated detail page → sub-tabs for that item's concerns. That's the
single biggest structural change here.

## 1. Stack constraints (unchanged — do not deviate)

- No build step, no bundler, no `.vue` SFCs. Plain PHP templates + inline `<script>`.
- **Vue 3** via CDN, Composition API, one component per page:
  `window.__APP.component('page', { template: '#tpl-page', setup() {...} })`.
- **Tailwind via CDN Play mode**, inline config, no config file. Utilities for layout/spacing;
  CSS custom properties for all color.
- Fonts: Oswald (headings/labels), Inter (body), JetBrains Mono (code). Toasts: `sonner-js`
  (`window.toast`).
- Data fetching via the global `apiFetch(path, opts)` helper. Router is server-side
  (page-to-page is a real navigation, not SPA routing) — a "sub-tab" within a page is a
  client-side Vue state switch (`v-if`/`v-show` on a `currentTab` ref), not a new URL, *unless*
  it's a genuine drill-down (list → detail), which **is** a new server route (see §3).

## 2. Design tokens (extend, don't replace)

Keep the existing palette — it's on-brand and fine — but it's incomplete for a real product.
Add:

```css
:root {
    /* existing, unchanged */
    --bg-main:    #0D1117;
    --bg-surface: #161B22;
    --bg-hover:   #1C2230;
    --text-main:  #FFFFFF;
    --text-muted: #8B949E;
    --accent:     #00F0FF;
    --accent-fg:  #0D1117;
    --accent-dim: rgba(0,240,255,0.10);
    --border:     #21262D;

    /* new */
    --danger:      #F85149;
    --danger-fg:   #FFFFFF;
    --danger-dim:  rgba(248,81,73,0.10);
    --success:     #3FB950;
    --success-dim: rgba(63,185,80,0.10);
    --warning:     #D29922;
    --warning-dim: rgba(210,153,34,0.10);

    --radius-sm: 6px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.24);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.32);
    --shadow-accent: 0 0 16px rgba(0,240,255,0.2); /* already used ad hoc on .btn-accent */
}

[data-theme="light"] {
    --bg-main:    #F0F4F8;
    --bg-surface: #FFFFFF;
    --bg-hover:   #F5F8FC;
    --text-main:  #121212;
    --text-muted: #556270;
    --accent:     #0088AA;
    --accent-fg:  #FFFFFF;
    --accent-dim: rgba(0,136,170,0.10);
    --border:     #D1D9E0;

    --danger:      #D1383A;
    --danger-fg:   #FFFFFF;
    --danger-dim:  rgba(209,56,58,0.08);
    --success:     #1A7F37;
    --success-dim: rgba(26,127,55,0.08);
    --warning:     #9A6700;
    --warning-dim: rgba(154,103,0,0.08);

    --shadow-sm: 0 1px 2px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.10);
}
```

Rule: no new raw hex in page markup, ever. If a page needs a color that isn't a token, that's
a sign a token is missing — add it here first, then use it.

## 3. Information architecture — drill-down over accordion

### 3.1 Principle

- **List page**: a grid/table of items (buckets, tables, functions, cron jobs, API keys,
  templates). Row/card click → navigates to a **detail page** (real route, real URL, back
  button works, refresh works). No inline accordion-expansion of a whole sub-resource tree.
- **Detail page**: breadcrumb back to the list, item's name as the page title, then a
  secondary tab bar for that item's own concerns (Files/Policies/Settings for a bucket;
  Runs/Logs/Settings for a cron job).
- Only use inline expand/collapse for genuinely small, flat things (a row's overflow menu, a
  single accordion FAQ) — never for "this bucket's files AND policies AND settings all at
  once."

### 3.2 Target sidebar (per project)

Reduce top-level nav sprawl by grouping related concerns, matching how a project owner
actually thinks about their backend:

```
Overview
Table Editor        (was: Tables)
SQL Editor
Database             ↳ RLS Policies (cross-table view), Migrations/Schema history (future)
Auth                  ↳ Users (was: End Users), Providers, Templates, Settings
Storage               ↳ Buckets (drill into a bucket for Files/Policies/Settings)
Functions
Cron Jobs
API Keys
Project Settings      (rename/description/danger zone — currently buried in Overview)
```

`End Users` stops being its own top-level sidebar item and becomes `Auth → Users` — end users
*are* what Auth manages; splitting them was an implementation-history artifact, not a mental
model users have.

### 3.3 Section sub-navigation pattern

A section with multiple concerns (Auth, Storage-bucket-detail, a future Table-detail) gets a
**second-level pill/underline tab row**, directly under the page title, above the content —
distinct from the existing top-level `.tab-link` bar (which stays as-is for the primary
sidebar-mirroring breadcrumb-ish bar, or is replaced by the sidebar entirely — see §3.4).

```html
<div class="subnav">
    <button class="subnav-item" :class="{ active: tab === 'users' }" @click="tab = 'users'">Users</button>
    <button class="subnav-item" :class="{ active: tab === 'providers' }" @click="tab = 'providers'">Providers</button>
    <button class="subnav-item" :class="{ active: tab === 'templates' }" @click="tab = 'templates'">Templates</button>
    <button class="subnav-item" :class="{ active: tab === 'settings' }" @click="tab = 'settings'">Settings</button>
</div>
```

```css
.subnav        { display:flex; gap:1.5rem; border-bottom:1px solid var(--border); margin-bottom:1.5rem; }
.subnav-item   { font-family:'Oswald',sans-serif; font-weight:500; font-size:0.78rem; text-transform:uppercase;
                 letter-spacing:0.06em; color:var(--text-muted); background:none; border:none; cursor:pointer;
                 padding:0 0 0.75rem; border-bottom:2px solid transparent; }
.subnav-item.active { color:var(--text-main); border-bottom-color:var(--accent); }
.subnav-item:hover:not(.active) { color:var(--text-main); }
```

This is a **client-side** switch inside one page component (one URL, one controller action,
one Vue component) — it's for *sibling concerns of the same item*, not for navigation between
different items. Contrast with §3.4.

### 3.4 Sidebar becomes the primary nav; drop the horizontal top-level tab bar

Today's horizontal `.tab-link` row (Overview/Tables/.../Auth) duplicates what the sidebar
already should show. Target: the **sidebar is the only primary nav**, restructured per §3.2,
with the previously-flat items now nested as shown. The current `_tabs.php` partial and its
horizontal bar are retired once the sidebar carries this structure — no page should have two
parallel primary-nav mechanisms.

### 3.5 List → detail routing (new)

Concretely, split these existing single-page-does-everything screens:

| Today (one page) | Target (list + detail) |
| --- | --- |
| `storage.php` (buckets + objects + policies, all expandable) | `storage/index` (bucket cards, name/visibility/file-count/created) → `storage/{bucket}` detail page with Files / Policies / Settings sub-tabs |
| `auth.php` (provider + toggle + templates, all on one scroll) | `auth/users` (was end-users.php, unchanged shape) · `auth/providers` (provider form + test-send, nothing else) · `auth/templates` (card grid) → `auth/templates/{key}` **dedicated editor page** (see §5.6), not a modal · `auth/settings` (require-confirmation toggle + future auth settings) |
| `cron-jobs.php` (list + create modal + run history inline?) | `cron-jobs/index` (list) → `cron-jobs/{id}` detail with Overview / Run History sub-tabs |
| `functions.php` | `functions/index` (list) → `functions/{id}` detail with Code / Test / Logs sub-tabs |

A "detail page" that's this data-light (e.g. a bucket with 2 fields of settings) still gets
the full breadcrumb+subnav treatment — consistency matters more than saving one page for a
thin case.

## 4. Navigation chrome

### 4.1 Breadcrumb

Every detail page starts with a breadcrumb, not just a "← back" link:

```html
<nav class="breadcrumb">
    <a href="/dashboard/projects">Projects</a>
    <span class="breadcrumb-sep">/</span>
    <a :href="`/dashboard/projects/${projectId}`">{{ project?.name }}</a>
    <span class="breadcrumb-sep">/</span>
    <a :href="`/dashboard/projects/${projectId}/storage`">Storage</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">{{ bucket?.name }}</span>
</nav>
```

```css
.breadcrumb         { display:flex; align-items:center; gap:0.4rem; font-size:0.78rem; color:var(--text-muted); margin-bottom:0.75rem; }
.breadcrumb a        { color:var(--text-muted); text-decoration:none; }
.breadcrumb a:hover  { color:var(--accent); }
.breadcrumb-sep      { opacity:0.5; }
.breadcrumb-current  { color:var(--text-main); font-weight:500; }
```

### 4.2 Sidebar collapse (responsive — currently entirely absent)

Below `1024px`: sidebar collapses to a 64px icon rail (labels hidden, tooltip on hover).
Below `768px`: sidebar becomes an off-canvas drawer opened by a hamburger button in the
topbar; `.main-wrapper` margin drops to 0. This lives in `templates/layouts/dashboard.php`
only — never patched per-page.

```css
@media (max-width: 1024px) {
    .sidebar { width: 64px; }
    .sidebar .nav-label, .sidebar .nav-section-label { display: none; }
    .main-wrapper, .topbar { margin-left: 64px; left: 64px; }
}
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); transition: transform .2s ease; width: 240px; z-index: 200; }
    .sidebar.open { transform: translateX(0); }
    .main-wrapper, .topbar { margin-left: 0; left: 0; }
    .hamburger { display: flex; }
}
.hamburger { display: none; }
```

Content grids inside pages should already be responsive via Tailwind (`grid md:grid-cols-2`,
`sm:` prefixes) — don't hardcode fixed multi-column layouts.

## 5. Component library (net-new, in addition to §7 of the old inventory)

Every one of these gets defined **once**, in the shared layout stylesheet, and reused
everywhere — no page may redeclare its own version.

### 5.1 Card

```css
.card       { background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius-lg);
              padding:1.25rem; transition:border-color .15s; }
.card-hover:hover { border-color:var(--accent); cursor:pointer; }
.card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:0.75rem; }
```

Used for every list-page item (bucket card, function card, cron job card, template card).

### 5.2 Badge

```css
.badge        { display:inline-flex; align-items:center; font-family:'Oswald',sans-serif; font-size:0.65rem;
                font-weight:500; text-transform:uppercase; letter-spacing:0.06em; padding:0.15rem 0.55rem;
                border-radius:4px; border:1px solid var(--border); color:var(--text-muted); }
.badge-accent  { background:var(--accent-dim); color:var(--accent); border-color:var(--accent); }
.badge-danger  { background:var(--danger-dim); color:var(--danger); border-color:var(--danger); }
.badge-success { background:var(--success-dim); color:var(--success); border-color:var(--success); }
.badge-warning { background:var(--warning-dim); color:var(--warning); border-color:var(--warning); }
```

### 5.3 Data list (replaces ad hoc flex-row lists)

Still not an HTML `<table>` (keep the existing flex-row aesthetic — it's fine and more
flexible for mixed content), but formalized as a component with a real header row, sticky on
scroll, and states:

```css
.datalist            { border:1px solid var(--border); border-radius:var(--radius-md); overflow:hidden; }
.datalist-head        { display:flex; align-items:center; padding:0.5rem 1rem; background:var(--bg-hover);
                         font-family:'Oswald',sans-serif; font-size:0.65rem; text-transform:uppercase;
                         letter-spacing:0.08em; color:var(--text-muted); position:sticky; top:0; }
.datalist-row          { display:flex; align-items:center; padding:0.65rem 1rem; border-top:1px solid var(--border); }
.datalist-row:hover    { background:var(--bg-hover); }
.datalist-cell         { flex:1; min-width:0; font-size:0.82rem; color:var(--text-main); }
```

### 5.4 Empty state

```html
<div class="empty-state">
    <svg class="empty-state-icon">...</svg>
    <p class="empty-state-title">No buckets yet</p>
    <p class="empty-state-body">Buckets group files with their own access policies.</p>
    <button class="btn-accent">+ New Bucket</button>
</div>
```

```css
.empty-state       { display:flex; flex-direction:column; align-items:center; text-align:center;
                      padding:3rem 1.5rem; border:1px dashed var(--border); border-radius:var(--radius-lg); }
.empty-state-icon   { width:2.5rem; height:2.5rem; color:var(--text-muted); margin-bottom:1rem; opacity:0.6; }
.empty-state-title  { font-family:'Oswald',sans-serif; font-weight:500; color:var(--text-main); margin-bottom:0.25rem; }
.empty-state-body   { font-size:0.82rem; color:var(--text-muted); margin-bottom:1rem; max-width:24rem; }
```

Every list page needs one of these — replaces plain "No buckets yet." text.

### 5.5 Skeleton loader (replaces plain "Loading…" text)

```css
.skeleton      { background:linear-gradient(90deg, var(--bg-hover) 25%, var(--border) 37%, var(--bg-hover) 63%);
                 background-size:400% 100%; animation:skeleton-loading 1.4s ease infinite; border-radius:4px; }
@keyframes skeleton-loading { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }
```

Use 2–3 stacked `.skeleton` bars of decreasing width shaped like the real content (a fake
card/row), not a spinner — this is what makes a loading list feel instant instead of janky.

### 5.6 Full-page split-editor (for email templates, edge function code — replaces modal editing)

A modal is wrong for anything with a meaningful amount of text to edit (email template body,
function source). Target pattern: a **dedicated route**, two-pane layout, save bar pinned at
the bottom:

```html
<main class="editor-page">
    <nav class="breadcrumb">...</nav>
    <div class="editor-split">
        <div class="editor-pane">
            <label class="field-label">Subject<input v-model="form.subject" class="input mt-1" /></label>
            <label class="field-label mt-3">Body<textarea v-model="form.body" class="input mt-1 font-mono" style="min-height:24rem;"></textarea></label>
        </div>
        <div class="editor-pane editor-preview">
            <p class="field-label mb-2">Preview</p>
            <div class="card" v-html="preview.body"></div>
        </div>
    </div>
    <div class="editor-savebar">
        <button class="btn-ghost-danger" @click="resetToDefault">Reset to default</button>
        <div class="flex gap-2">
            <a class="btn-ghost" :href="backHref">Cancel</a>
            <button class="btn-accent" @click="save">Save changes</button>
        </div>
    </div>
</main>
```

```css
.editor-split    { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
@media (max-width: 900px) { .editor-split { grid-template-columns:1fr; } }
.editor-savebar  { position:sticky; bottom:0; display:flex; justify-content:space-between; align-items:center;
                    padding:1rem; margin-top:1.5rem; background:var(--bg-surface); border:1px solid var(--border);
                    border-radius:var(--radius-md); box-shadow:var(--shadow-md); }
```

Live preview re-renders on input (debounced `apiFetch` to the existing `/templates/preview`
endpoint, or client-side `{{placeholder}}` substitution with sample data for instant feedback,
falling back to the real endpoint for exact HTML-escaping parity).

### 5.7 Search / filter bar

Any list expected to grow past ~10 items (end users, functions, cron job runs) gets a bar
above the list:

```html
<div class="listbar">
    <input v-model="search" class="input" style="max-width:20rem;" placeholder="Search by email…" />
    <select v-model="roleFilter" class="input" style="max-width:10rem;">
        <option value="">All roles</option>
        <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
    </select>
    <div class="flex-1"></div>
    <button class="btn-accent">+ New</button>
</div>
```

```css
.listbar { display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem; flex-wrap:wrap; }
```

### 5.8 Pagination control (the API already returns `X-Total-Count`/`X-Page-Limit`/`X-Page-Offset` — surface it)

```html
<div class="pagination">
    <span class="pagination-info">{{ offset + 1 }}–{{ Math.min(offset + limit, total) }} of {{ total }}</span>
    <div class="flex gap-2">
        <button class="btn-ghost" :disabled="offset === 0" @click="prevPage">Previous</button>
        <button class="btn-ghost" :disabled="offset + limit >= total" @click="nextPage">Next</button>
    </div>
</div>
```

```css
.pagination      { display:flex; align-items:center; justify-content:space-between; margin-top:1rem; }
.pagination-info { font-size:0.78rem; color:var(--text-muted); }
```

Every list endpoint that already exposes these headers (End Users, API Keys, RLS Policies —
see `Pagination.php`) currently has **no pagination UI at all** on the frontend; this is a gap
to close, not a new backend feature.

### 5.9 Drawer (for a quick peek without leaving the list)

For things that don't warrant a full navigation (viewing one end user's details, one cron
run's log output), use a right-side slide-in drawer instead of either a cramped modal or a
full page:

```css
.drawer-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:100; }
.drawer          { position:fixed; top:0; right:0; bottom:0; width:min(28rem, 100vw); background:var(--bg-surface);
                    border-left:1px solid var(--border); box-shadow:var(--shadow-md); z-index:101;
                    display:flex; flex-direction:column; padding:1.5rem; overflow-y:auto;
                    animation:drawer-in .2s ease; }
@keyframes drawer-in { from { transform:translateX(100%); } to { transform:translateX(0); } }
```

Rule of thumb: **drawer** for "glance at read-mostly detail from a list," **dedicated page**
for "edit something substantial or with its own sub-navigation," **modal** only for a single
short form (create-bucket-name, confirm-delete) — never for editing body text or showing
tabbed content.

## 6. Interaction polish expected at this tier

- **Copy-to-clipboard** button (icon-only, `.btn-ghost` sized down) next to every API key,
  token, project ID, public URL shown in the UI — currently absent everywhere.
- **Inline field validation** (red border + small text under the field) instead of only a
  toast on submit failure, for every form with more than 2 fields (email config, bucket
  creation, cron job creation).
- **Optimistic-safe destructive actions**: the existing `askConfirm()` pattern is fine —
  keep it, but the confirm copy must always name the exact item (`Delete bucket "avatars"?`,
  never `Delete this bucket?`).
- **Keyboard**: `Esc` closes any open modal/drawer; `Cmd/Ctrl+K` opens a command palette is a
  stretch goal, not required for this pass — don't build it unless asked.
- **Unsaved-changes guard** on the full-page editor (§5.6): if `form` differs from the loaded
  value and the user navigates away, confirm via the browser's native
  `beforeunload`/Vue route-leave equivalent (since routing is server-side, a simple
  `window.onbeforeunload` guard is enough — no router hooks exist to hang this on).

## 7. Landing page

Out of scope for this pass — `templates/site/index.php` is a separate, already-decent,
hand-styled marketing page. Do not merge its token set into the dashboard's or vice versa in
this pass; that unification is a separate, smaller cleanup (tracked as a known gap, not part
of "market-level dashboard" work).

## 8. Migration priority (do in this order)

1. Land the token additions (§2) and the new shared components (§5) in
   `templates/layouts/dashboard.php` — nothing else can start until these exist.
2. Restructure the sidebar (§3.2) and retire `_tabs.php` (§3.4).
3. Split Storage into list + bucket-detail (§3.5) — it's the most tangled existing page and
   the best proof this pattern works end-to-end (breadcrumb, subnav, drawer for a quick file
   preview instead of the current inline image-preview modal).
4. Split Auth into Users / Providers / Templates / Settings (§3.5), with Templates getting the
   full-page split-editor (§5.6) instead of the modal shipped in the current `auth.php`.
5. Apply search/filter/pagination (§5.7, §5.8) to every list that already has backend
   pagination but no frontend controls for it (End Users, API Keys, RLS Policies).
6. Everything else (Functions, Cron Jobs detail split) follows the same recipe.
