<?php
/** @var string|null $activeNav */
/** @var int|string|null $projectId */
$activeNav ??= null;
$projectId ??= null;

// Top-level sidebar items. Each optionally carries 'subs' (rendered as indented
// nav-sub links right after it) — see ui.md §3.2 for the target IA this mirrors.
$projectNavItems = [
    ['overview', '', 'Overview', '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>'],
    ['table-editor', '/table-editor', 'Table Editor', '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M9 9v11"/>'],
    ['sql-editor', '/sql', 'SQL Editor', '<path d="M4 5c0-1 1.8-2 4-2 2.2 0 4 1 4 2s-1.8 2-4 2c-2.2 0-4-1-4-2Z"/><path d="M4 5v14c0 1 1.8 2 4 2 2.2 0 4-1 4-2V5"/><path d="M16 3v18"/><path d="M20 3v18"/>'],
    ['database', '/database', 'Database', '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5"/><path d="M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/>'],
    ['auth', '/auth/users', 'Auth', '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>', [
        ['auth-users', '/auth/users', 'Users'],
        ['auth-providers', '/auth/providers', 'Providers'],
        ['auth-templates', '/auth/templates', 'Templates'],
        ['auth-settings', '/auth/settings', 'Settings'],
    ]],
    ['storage', '/storage', 'Storage', '<path d="M3 7 12 3l9 4-9 4-9-4Z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>', [
        ['storage-buckets', '/storage', 'Buckets'],
    ]],
    ['functions', '/functions', 'Functions', '<polyline points="8 4 4 12 8 20"/><polyline points="16 4 20 12 16 20"/>'],
    ['cron-jobs', '/cron-jobs', 'Cron Jobs', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>'],
    ['keys', '/keys', 'API Keys', '<circle cx="8" cy="14" r="4"/><path d="M10.5 11.5 20 2"/><path d="M16 6l2 2"/><path d="M19 3l2 2"/>'],
    ['settings', '/settings', 'Project Settings', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.6-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.6V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.6 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1Z"/>'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page['info']['title'] ?? 'Loxodontu') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        head: ['Oswald', 'sans-serif'],
                        body: ['Inter', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --bg-main:    #0D1117;
            --bg-surface: #161B22;
            --bg-hover:   #1C2230;
            --text-main:  #FFFFFF;
            --text-muted: #8B949E;
            --accent:     #00F0FF;
            --accent-fg:  #0D1117;
            --accent-dim: rgba(0,240,255,0.10);
            --border:     #21262D;

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
            --shadow-accent: 0 0 16px rgba(0,240,255,0.2);
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

        * { box-sizing: border-box; }
        html, body { background: var(--bg-main); color: var(--text-main); font-family: 'Inter', sans-serif; margin:0; padding:0; -webkit-font-smoothing: antialiased; }
        .font-head, .font-oswald { font-family: 'Oswald', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        a { color: inherit; }

        .no-auth .sidebar, .no-auth .topbar, .no-auth .sidebar-backdrop-nav { display: none; }
        .no-auth .main-wrapper { margin-left: 0; min-height: 100vh; }

        /* ---------- App shell ---------- */
        .app-shell { min-height: 100vh; }
        .sidebar { position:fixed; top:0; left:0; bottom:0; width:240px; background:var(--bg-surface); border-right:1px solid var(--border); display:flex; flex-direction:column; z-index:150; transition:width .2s ease, transform .2s ease; }
        .sidebar-brand { display:flex; align-items:center; gap:0.6rem; padding:1.1rem 1.25rem; border-bottom:1px solid var(--border); font-family:'Oswald',sans-serif; font-weight:600; font-size:1rem; letter-spacing:0.02em; white-space:nowrap; overflow:hidden; text-decoration:none; }
        .sidebar-brand .dot { width:10px; height:10px; border-radius:3px; background:var(--accent); box-shadow:var(--shadow-accent); flex-shrink:0; }

        /* ---------- Project switcher ---------- */
        .project-switcher { position:relative; margin:0.6rem 0.6rem 0.4rem; }
        .project-switcher-trigger { display:flex; align-items:center; gap:0.5rem; width:100%; padding:0.5rem 0.6rem; background:var(--bg-hover); border:1px solid var(--border); border-radius:var(--radius-md); cursor:pointer; text-align:left; }
        .project-switcher-trigger:hover { border-color:var(--accent); }
        .project-switcher-avatar { width:22px; height:22px; border-radius:6px; flex-shrink:0; background:var(--accent-dim); color:var(--accent); display:flex; align-items:center; justify-content:center; font-family:'Oswald',sans-serif; font-size:0.65rem; font-weight:700; }
        .project-switcher-name { flex:1; min-width:0; font-size:0.82rem; font-weight:600; color:var(--text-main); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .project-switcher-region { font-size:0.65rem; color:var(--text-muted); display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .project-switcher-menu { position:absolute; top:calc(100% + 4px); left:0; right:0; background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-md); z-index:160; padding:0.35rem; display:none; }
        .project-switcher-menu.open { display:block; }
        .project-switcher-item { display:flex; align-items:center; gap:0.5rem; padding:0.45rem 0.5rem; border-radius:var(--radius-sm); font-size:0.8rem; color:var(--text-main); cursor:pointer; text-decoration:none; }
        .project-switcher-item:hover { background:var(--bg-hover); }
        .project-switcher-item.muted { color:var(--text-muted); }
        .project-switcher-sep { height:1px; background:var(--border); margin:0.3rem 0; }

        .sidebar-nav { flex:1; overflow-y:auto; padding:0.75rem 0.6rem; }
        .nav-section { margin-bottom:1.1rem; }
        .nav-section-label { font-family:'Oswald',sans-serif; font-size:0.65rem; text-transform:uppercase; letter-spacing:0.1em; color:var(--text-muted); padding:0 0.6rem; margin-bottom:0.4rem; white-space:nowrap; overflow:hidden; }
        .nav-item { display:flex; align-items:center; gap:0.65rem; padding:0.5rem 0.6rem; border-radius:var(--radius-sm); color:var(--text-muted); text-decoration:none; font-size:0.85rem; margin-bottom:2px; white-space:nowrap; overflow:hidden; }
        .nav-item svg { width:16px; height:16px; flex-shrink:0; }
        .nav-item:hover { background:var(--bg-hover); color:var(--text-main); }
        .nav-item.active { background:var(--accent-dim); color:var(--accent); font-weight:500; }
        .nav-item.nav-sub { padding-left:2.15rem; font-size:0.8rem; }
        .nav-label { overflow:hidden; text-overflow:ellipsis; }
        .sidebar-footer { padding:0.75rem; border-top:1px solid var(--border); }

        .topbar { position:sticky; top:0; height:56px; background:var(--bg-main); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; padding:0 1.25rem; margin-left:240px; z-index:90; }
        .main-wrapper { margin-left:240px; }
        .page-content { padding:1.75rem; max-width:1280px; }

        .icon-btn { position:relative; display:flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:var(--radius-sm); border:1px solid var(--border); background:transparent; color:var(--text-muted); cursor:pointer; }
        .icon-btn:hover { color:var(--text-main); border-color:var(--accent); }

        .avatar-chip { display:flex; align-items:center; gap:0.5rem; padding:0.25rem 0.6rem 0.25rem 0.25rem; border-radius:var(--radius-md); border:1px solid var(--border); }
        .avatar-chip .initials { width:26px; height:26px; border-radius:50%; background:var(--accent-dim); color:var(--accent); display:flex; align-items:center; justify-content:center; font-family:'Oswald',sans-serif; font-size:0.68rem; font-weight:700; }
        .avatar-chip span.name { font-size:0.8rem; color:var(--text-main); }

        /* ---------- Connect card ---------- */
        .kv-row { display:flex; align-items:center; gap:0.75rem; padding:0.65rem 0.85rem; border:1px solid var(--border); border-radius:var(--radius-md); background:var(--bg-main); }
        .kv-row + .kv-row { margin-top:0.6rem; }
        .kv-label { flex:0 0 8rem; font-family:'Oswald',sans-serif; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); }
        .kv-value { flex:1; min-width:0; font-family:'JetBrains Mono',monospace; font-size:0.8rem; color:var(--text-main); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .sparkline { display:block; opacity:0.9; }

        /* ---------- Modal ---------- */
        .modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:200; padding:1rem; }
        .modal-card { width:100%; max-width:28rem; max-height:calc(100vh - 2rem); overflow-y:auto; background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:1.5rem; box-shadow:var(--shadow-md); }
        .modal-title { font-family:'Oswald',sans-serif; font-weight:700; font-size:1rem; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:1rem; color:var(--text-main); }

        /* ---------- Table Editor grid ---------- */
        .te-layout { display:flex; border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; height:34rem; }
        .te-tables { width:15rem; flex-shrink:0; border-right:1px solid var(--border); background:var(--bg-surface); display:flex; flex-direction:column; }
        .te-tables-head { padding:0.7rem 1rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
        .te-tables-list { flex:1; overflow-y:auto; padding:0.4rem; }
        .te-table-item { display:flex; align-items:center; justify-content:space-between; gap:0.5rem; padding:0.5rem 0.65rem; font-size:0.82rem; color:var(--text-muted); cursor:pointer; border-radius:var(--radius-sm); margin-bottom:2px; }
        .te-table-item:hover { background:var(--bg-hover); color:var(--text-main); }
        .te-table-item.active { background:var(--accent-dim); color:var(--accent); font-weight:500; }
        .te-table-item .count { font-size:0.68rem; color:var(--text-muted); font-family:'JetBrains Mono',monospace; flex-shrink:0; }
        .te-table-item.active .count { color:var(--accent); opacity:0.8; }
        .te-main { flex:1; min-width:0; display:flex; flex-direction:column; background:var(--bg-main); }
        .te-toolbar { display:flex; align-items:center; gap:0.6rem; padding:0.65rem 0.85rem; border-bottom:1px solid var(--border); flex-wrap:wrap; background:var(--bg-surface); }
        .te-grid-wrap { flex:1; overflow:auto; }
        table.te-grid { border-collapse:collapse; width:100%; font-size:0.82rem; }
        .te-grid thead th { position:sticky; top:0; background:var(--bg-hover); border-bottom:1px solid var(--border); border-right:1px solid var(--border); padding:0; text-align:left; z-index:2; }
        .te-th-inner { display:flex; align-items:center; gap:0.35rem; padding:0.5rem 0.7rem; cursor:pointer; user-select:none; white-space:nowrap; }
        .te-th-inner:hover .te-col-name { color:var(--text-main); }
        .te-col-name { font-weight:500; color:var(--text-muted); }
        .te-col-type { font-size:0.6rem; text-transform:uppercase; color:var(--text-muted); font-family:'JetBrains Mono',monospace; opacity:0.7; }
        .te-sort-caret { font-size:0.6rem; color:var(--accent); }
        .te-col-del { opacity:0.35; margin-left:auto; flex-shrink:0; }
        .te-col-del:hover { opacity:1; color:var(--danger); }
        .te-grid td { border-bottom:1px solid var(--border); border-right:1px solid var(--border); padding:0; white-space:nowrap; }
        .te-cell { display:block; padding:0.42rem 0.7rem; font-family:'JetBrains Mono',monospace; font-size:0.78rem; color:var(--text-main); min-width:7rem; cursor:text; }
        .te-cell:hover { background:var(--bg-hover); }
        .te-cell.te-null { color:var(--text-muted); font-style:italic; }
        .te-cell-input { width:100%; box-sizing:border-box; background:var(--bg-hover); border:1px solid var(--accent); border-radius:4px; padding:0.38rem 0.55rem; font-family:'JetBrains Mono',monospace; font-size:0.78rem; color:var(--text-main); outline:none; }
        .te-gutter { width:2.5rem; min-width:2.5rem; text-align:center; background:var(--bg-hover); }
        .te-row-num { color:var(--text-muted); font-size:0.72rem; }
        .te-row:hover .te-row-num { display:none; }
        .te-row-del { display:none; align-items:center; justify-content:center; width:100%; color:var(--text-muted); cursor:pointer; }
        .te-row:hover .te-row-del { display:flex; }
        .te-row-del:hover { color:var(--danger); }
        .te-add-col-th { width:2.75rem; text-align:center; cursor:pointer; color:var(--text-muted); }
        .te-add-col-th:hover { color:var(--accent); background:var(--bg-hover); }
        .te-empty-row td { padding:3rem 1rem; text-align:center; color:var(--text-muted); font-size:0.82rem; }

        .hamburger { display:none; align-items:center; justify-content:center; width:34px; height:34px; border-radius:var(--radius-sm); border:1px solid var(--border); background:var(--bg-surface); cursor:pointer; }
        .sidebar-backdrop-nav { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:140; display:none; }
        .sidebar-backdrop-nav.open { display:block; }

        @media (max-width: 1024px) {
            .sidebar { width:64px; }
            .sidebar .nav-label, .sidebar .nav-section-label, .sidebar-brand span { display:none; }
            .main-wrapper, .topbar { margin-left:64px; }
        }
        @media (max-width: 768px) {
            .sidebar { transform:translateX(-100%); transition:transform .2s ease; width:240px; z-index:200; }
            .sidebar .nav-label, .sidebar .nav-section-label, .sidebar-brand span { display:inline; }
            .sidebar.open { transform:translateX(0); }
            .main-wrapper, .topbar { margin-left:0; }
            .hamburger { display:flex; }
            .page-content { padding:1.25rem; }
        }

        /* ---------- Breadcrumb ---------- */
        .breadcrumb { display:flex; align-items:center; gap:0.4rem; font-size:0.78rem; color:var(--text-muted); margin-bottom:0.75rem; flex-wrap:wrap; }
        .breadcrumb a { color:var(--text-muted); text-decoration:none; }
        .breadcrumb a:hover { color:var(--accent); }
        .breadcrumb-sep { opacity:0.5; }
        .breadcrumb-current { color:var(--text-main); font-weight:500; }

        /* ---------- Subnav ---------- */
        .subnav { display:flex; gap:1.5rem; border-bottom:1px solid var(--border); margin-bottom:1.5rem; overflow-x:auto; }
        .subnav-item { font-family:'Oswald',sans-serif; font-weight:500; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); background:none; border:none; cursor:pointer; padding:0 0 0.75rem; border-bottom:2px solid transparent; white-space:nowrap; text-decoration:none; display:inline-block; }
        .subnav-item.active { color:var(--text-main); border-bottom-color:var(--accent); }
        .subnav-item:hover:not(.active) { color:var(--text-main); }

        /* ---------- Card ---------- */
        .card { background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:1.25rem; transition:border-color .15s; }
        .card-hover:hover { border-color:var(--accent); cursor:pointer; }
        .card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:0.75rem; }

        /* ---------- Badge ---------- */
        .badge { display:inline-flex; align-items:center; font-family:'Oswald',sans-serif; font-size:0.65rem; font-weight:500; text-transform:uppercase; letter-spacing:0.06em; padding:0.15rem 0.55rem; border-radius:4px; border:1px solid var(--border); color:var(--text-muted); }
        .badge-accent { background:var(--accent-dim); color:var(--accent); border-color:var(--accent); }
        .badge-danger { background:var(--danger-dim); color:var(--danger); border-color:var(--danger); }
        .badge-success { background:var(--success-dim); color:var(--success); border-color:var(--success); }
        .badge-warning { background:var(--warning-dim); color:var(--warning); border-color:var(--warning); }

        /* ---------- Data list ---------- */
        .datalist { border:1px solid var(--border); border-radius:var(--radius-md); overflow:hidden; }
        .datalist-head { display:flex; align-items:center; padding:0.5rem 1rem; background:var(--bg-hover); font-family:'Oswald',sans-serif; font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:var(--text-muted); }
        .datalist-row { display:flex; align-items:center; padding:0.65rem 1rem; border-top:1px solid var(--border); }
        .datalist-row:hover { background:var(--bg-hover); }
        .datalist-cell { flex:1; min-width:0; font-size:0.82rem; color:var(--text-main); }

        /* ---------- Empty state ---------- */
        .empty-state { display:flex; flex-direction:column; align-items:center; text-align:center; padding:3rem 1.5rem; border:1px dashed var(--border); border-radius:var(--radius-lg); }
        .empty-state-icon { width:2.5rem; height:2.5rem; color:var(--text-muted); margin-bottom:1rem; opacity:0.6; }
        .empty-state-title { font-family:'Oswald',sans-serif; font-weight:500; color:var(--text-main); margin-bottom:0.25rem; }
        .empty-state-body { font-size:0.82rem; color:var(--text-muted); margin-bottom:1rem; max-width:24rem; }

        /* ---------- Skeleton ---------- */
        .skeleton { background:linear-gradient(90deg, var(--bg-hover) 25%, var(--border) 37%, var(--bg-hover) 63%); background-size:400% 100%; animation:skeleton-loading 1.4s ease infinite; border-radius:4px; }
        @keyframes skeleton-loading { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }

        /* ---------- Editor split ---------- */
        .editor-split { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; }
        @media (max-width: 900px) { .editor-split { grid-template-columns:1fr; } }
        .editor-savebar { position:sticky; bottom:0; display:flex; justify-content:space-between; align-items:center; padding:1rem; margin-top:1.5rem; background:var(--bg-surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-md); }

        /* ---------- Listbar / Pagination ---------- */
        .listbar { display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem; flex-wrap:wrap; }
        .pagination { display:flex; align-items:center; justify-content:space-between; margin-top:1rem; }
        .pagination-info { font-size:0.78rem; color:var(--text-muted); }

        /* ---------- Drawer ---------- */
        .drawer-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:100; }
        .drawer { position:fixed; top:0; right:0; bottom:0; width:min(28rem, 100vw); background:var(--bg-surface); border-left:1px solid var(--border); box-shadow:var(--shadow-md); z-index:101; display:flex; flex-direction:column; padding:1.5rem; overflow-y:auto; animation:drawer-in .2s ease; }
        @keyframes drawer-in { from { transform:translateX(100%); } to { transform:translateX(0); } }

        /* ---------- Buttons & inputs ---------- */
        .btn-accent, .btn-ghost, .btn-ghost-danger { font-family:'Oswald',sans-serif; font-size:0.75rem; font-weight:500; text-transform:uppercase; letter-spacing:0.04em; border-radius:var(--radius-sm); padding:0.5rem 0.9rem; cursor:pointer; display:inline-flex; align-items:center; gap:0.4rem; border:1px solid transparent; text-decoration:none; line-height:1.2; transition:filter .1s, border-color .1s, background .1s; }
        .btn-accent { background:var(--accent); color:var(--accent-fg); font-weight:700; box-shadow:var(--shadow-accent); }
        .btn-accent:hover { filter:brightness(1.08); }
        .btn-accent:disabled { opacity:0.5; cursor:not-allowed; box-shadow:none; }
        .btn-accent.btn-quiet { box-shadow:none; }
        .btn-ghost { background:transparent; color:var(--text-main); border-color:var(--border); }
        .btn-ghost:hover { background:var(--bg-hover); }
        .btn-ghost:disabled { opacity:0.4; cursor:not-allowed; }
        .btn-ghost-danger { background:transparent; color:var(--danger); border-color:var(--border); }
        .btn-ghost-danger:hover { background:var(--danger-dim); border-color:var(--danger); }
        .btn-icon { padding:0.4rem; }

        .input { width:100%; background:var(--bg-main); border:1px solid var(--border); border-radius:var(--radius-sm); color:var(--text-main); font-size:0.85rem; padding:0.55rem 0.75rem; font-family:'Inter',sans-serif; }
        .input:focus { outline:none; border-color:var(--accent); }
        .input.input-error { border-color:var(--danger); }
        .field-error { color:var(--danger); font-size:0.72rem; margin-top:0.3rem; display:block; }
        .field-label { font-family:'Oswald',sans-serif; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); display:block; }

        .page-title { font-family:'Oswald',sans-serif; font-size:1.5rem; font-weight:700; text-transform:uppercase; letter-spacing:0.02em; }
        .page-subtitle { color:var(--text-muted); font-size:0.85rem; margin-top:0.15rem; }

        ::-webkit-scrollbar { width:10px; height:10px; }
        ::-webkit-scrollbar-thumb { background:var(--border); border-radius:8px; }
        ::-webkit-scrollbar-track { background:transparent; }
    </style>
    <script>
        (function () {
            var stored = localStorage.getItem('loxo-theme');
            if (stored === 'light') document.documentElement.setAttribute('data-theme', 'light');
            if (!localStorage.getItem('loxo-auth')) document.documentElement.classList.add('no-auth');
        })();
    </script>
</head>

<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a href="/dashboard/projects" class="sidebar-brand">
                <span class="dot"></span>
                <span>Loxodontu</span>
            </a>

            <?php if ($projectId): ?>
                <div class="project-switcher">
                    <button class="project-switcher-trigger" onclick="document.getElementById('project-switcher-menu').classList.toggle('open')">
                        <span class="project-switcher-avatar" id="project-switcher-avatar">--</span>
                        <span style="min-width:0;">
                            <span class="project-switcher-name" id="project-switcher-name">&hellip;</span>
                            <span class="project-switcher-region" id="project-switcher-id"></span>
                        </span>
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;color:var(--text-muted)"><polyline points="6 9 12 15 18 9" /></svg>
                    </button>
                    <div id="project-switcher-menu" class="project-switcher-menu">
                        <div id="project-switcher-list"></div>
                        <div class="project-switcher-sep"></div>
                        <a class="project-switcher-item muted" href="/dashboard/projects">All projects</a>
                        <a class="project-switcher-item muted" href="/dashboard/projects">+ New project</a>
                    </div>
                </div>
            <?php endif; ?>

            <nav class="sidebar-nav">
                <?php if (!$projectId): ?>
                    <div class="nav-section">
                        <div class="nav-section-label">General</div>
                        <a href="/dashboard/projects" class="nav-item <?= $activeNav === 'projects' ? 'active' : '' ?>" title="Projects">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                            <span class="nav-label">Projects</span>
                        </a>
                        <a href="/dashboard/account" class="nav-item <?= $activeNav === 'account' ? 'active' : '' ?>" title="Account">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5.121 17.804A9 9 0 1118.88 6.196M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            <span class="nav-label">Account</span>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="nav-section">
                        <div class="nav-section-label">Project</div>
                        <?php foreach ($projectNavItems as $navItem): [$id, $suffix, $label, $icon, $subs] = $navItem + [4 => null]; ?>
                            <a href="/dashboard/projects/<?= e((string) $projectId) ?><?= $suffix ?>" class="nav-item <?= $activeNav === $id ? 'active' : '' ?>" title="<?= e($label) ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
                                <span class="nav-label"><?= e($label) ?></span>
                            </a>
                            <?php foreach ($subs ?? [] as [$subId, $subSuffix, $subLabel]): ?>
                                <a href="/dashboard/projects/<?= e((string) $projectId) ?><?= $subSuffix ?>" class="nav-item nav-sub <?= $activeNav === $subId ? 'active' : '' ?>">
                                    <span class="nav-label"><?= e($subLabel) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="flex items-center justify-between">
                    <span class="text-xs" style="color:var(--text-muted);">Loxodontu &copy; <?= date('Y') ?></span>
                    <button onclick="toggleTheme()" class="btn-ghost btn-icon" title="Toggle theme">
                        <span id="theme-icon">&#9728;&#65039;</span>
                    </button>
                </div>
            </div>
        </aside>
        <div id="sidebar-backdrop" class="sidebar-backdrop-nav" onclick="closeSidebar()"></div>

        <div class="main-wrapper">
            <header class="topbar">
                <div class="flex items-center gap-3">
                    <button class="hamburger" onclick="toggleSidebar()">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" /></svg>
                    </button>
                    <span class="font-head font-bold text-sm uppercase tracking-wider"><?= e($page['info']['pageTitle'] ?? 'Dashboard') ?></span>
                </div>
                <div class="flex items-center gap-3">
                    <a href="/dashboard/account" class="avatar-chip" style="text-decoration:none;">
                        <span class="initials" id="user-initials">--</span>
                        <span class="name" id="user-name-chip"></span>
                    </a>
                    <button onclick="platformLogout()" class="btn-ghost" title="Log out">Log out</button>
                </div>
            </header>

            <main class="page-content">
                <div id="app">
                    <page></page>
                </div>
            </main>
        </div>
    </div>

    <?= $page['body'] ?? '' ?>

    <script src="https://cdn.jsdelivr.net/npm/vue@3.4.31/dist/vue.global.prod.js"></script>

    <script type="module">
        import toast from 'https://cdn.jsdelivr.net/npm/sonner-js/+esm';
        window.toast = toast;
    </script>

    <script>
        const store = Vue.reactive({
            auth: JSON.parse(localStorage.getItem('loxo-auth') || 'null'),
        });

        function refreshUserChip() {
            const initials = document.getElementById('user-initials');
            const nameChip = document.getElementById('user-name-chip');
            if (!initials || !nameChip) return;
            if (!store.auth) { initials.textContent = '--'; nameChip.textContent = ''; return; }
            const name = store.auth.user.name || store.auth.user.email || '';
            const parts = name.trim().split(' ');
            initials.textContent = parts.length > 1
                ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
                : parts[0].slice(0, 2).toUpperCase();
            nameChip.textContent = name;
        }
        document.addEventListener('DOMContentLoaded', refreshUserChip);

        async function apiFetch(path, opts) {
            opts = opts || {};
            const headers = Object.assign({ 'Content-Type': 'application/json' }, opts.headers || {});
            if (store.auth) headers.Authorization = 'Bearer ' + store.auth.token;

            const res = await fetch('/api/v1' + path, Object.assign({}, opts, { headers }));

            if (res.status === 401 && store.auth) {
                localStorage.removeItem('loxo-auth');
                location.href = '/dashboard';
                throw new Error('Unauthorized');
            }

            let body = null;
            if (res.status !== 204) {
                const text = await res.text();
                body = text ? JSON.parse(text) : null;
            }

            if (!res.ok) {
                throw new Error((body && body.error) || `Request failed (${res.status})`);
            }

            return { body, headers: res.headers };
        }
        window.apiFetch = apiFetch;

        async function platformLogout() {
            try {
                await fetch('/api/v1/auth/logout', {
                    method: 'POST',
                    headers: { Authorization: 'Bearer ' + (store.auth ? store.auth.token : '') },
                });
            } catch (e) { /* ignore network errors on logout */ }
            localStorage.removeItem('loxo-auth');
            location.href = '/dashboard';
        }
        window.platformLogout = platformLogout;

        // ---------- Theme ----------
        function toggleTheme() {
            const el = document.documentElement;
            const next = el.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            if (next === 'light') el.setAttribute('data-theme', 'light');
            else el.removeAttribute('data-theme');
            localStorage.setItem('loxo-theme', next);
            const icon = document.getElementById('theme-icon');
            if (icon) icon.textContent = next === 'light' ? '\u{1F319}' : '\u{2600}\u{FE0F}';
        }
        window.toggleTheme = toggleTheme;
        document.addEventListener('DOMContentLoaded', () => {
            const icon = document.getElementById('theme-icon');
            if (icon) icon.textContent = document.documentElement.getAttribute('data-theme') === 'light' ? '\u{1F319}' : '\u{2600}\u{FE0F}';
        });

        // ---------- Mobile sidebar ----------
        function toggleSidebar() {
            document.querySelector('.sidebar')?.classList.toggle('open');
            document.getElementById('sidebar-backdrop')?.classList.toggle('open');
        }
        function closeSidebar() {
            document.querySelector('.sidebar')?.classList.remove('open');
            document.getElementById('sidebar-backdrop')?.classList.remove('open');
        }
        window.toggleSidebar = toggleSidebar;
        window.closeSidebar = closeSidebar;

        // ---------- Copy to clipboard ----------
        async function copyToClipboard(text, btn) {
            try {
                await navigator.clipboard.writeText(text);
                if (window.toast) toast.success('Copied to clipboard');
                if (btn) {
                    const original = btn.innerHTML;
                    btn.textContent = '✓';
                    setTimeout(() => { btn.innerHTML = original; }, 1200);
                }
            } catch (e) {
                if (window.toast) toast.error('Could not copy');
            }
        }
        window.copyToClipboard = copyToClipboard;

        // ---------- Project switcher dropdown ----------
        document.addEventListener('click', (e) => {
            const menu = document.getElementById('project-switcher-menu');
            if (!menu) return;
            const trigger = menu.closest('.project-switcher')?.querySelector('.project-switcher-trigger');
            if (menu.contains(e.target) || (trigger && trigger.contains(e.target))) return;
            menu.classList.remove('open');
        });

        // ---------- Esc closes drawers/modals ----------
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            document.querySelectorAll('[data-dismiss-on-esc]').forEach((el) => {
                if (typeof el.__close === 'function') el.__close();
                else el.classList.add('hidden');
            });
        });

        window.__APP = Vue.createApp({ setup() { return {}; } });
        __APP.provide('store', store);

        <?php if ($projectId): ?>
        // Shared current-project fetch: every page component can `Vue.inject('project')`
        // instead of duplicating this call, and the sidebar switcher/avatar use it too.
        const PROJECT_ID = <?= json_encode((string) $projectId) ?>;
        const project = Vue.ref(null);
        const otherProjects = Vue.ref([]);
        __APP.provide('project', project);
        __APP.provide('projectId', PROJECT_ID);

        function renderProjectChrome() {
            if (!project.value) return;
            const avatar = document.getElementById('project-switcher-avatar');
            const name = document.getElementById('project-switcher-name');
            const idEl = document.getElementById('project-switcher-id');
            if (avatar) avatar.textContent = project.value.name.slice(0, 2).toUpperCase();
            if (name) name.textContent = project.value.name;
            if (idEl) idEl.textContent = project.value.id;
        }

        function renderProjectSwitcherList() {
            const list = document.getElementById('project-switcher-list');
            if (!list) return;
            list.innerHTML = otherProjects.value.map((p) => `
                <a class="project-switcher-item ${p.id === PROJECT_ID ? '' : 'muted'}" href="/dashboard/projects/${p.id}">
                    <span class="project-switcher-avatar" style="width:18px;height:18px;font-size:0.55rem;">${p.name.slice(0, 2).toUpperCase()}</span> ${p.name}
                </a>
            `).join('');
        }

        (async function loadProjectChrome() {
            try {
                const { body } = await apiFetch(`/projects/${PROJECT_ID}`);
                project.value = body;
                renderProjectChrome();
            } catch (e) { /* sidebar chrome is non-critical */ }
            try {
                const { body } = await apiFetch('/projects');
                otherProjects.value = body;
                renderProjectSwitcherList();
            } catch (e) { /* non-critical */ }
        })();
        <?php endif; ?>
    </script>

    <?= $page['script'] ?? '' ?>

    <script>
        __APP.mount('#app');
    </script>
</body>

</html>
