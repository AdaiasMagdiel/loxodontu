<?php
/** @var string|null $activeNav */
/** @var int|null $projectId */
$activeNav ??= null;
$projectId ??= null;

$projectNavItems = [
    ['overview', '', 'Overview', 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['tables', '/tables', 'Tables', 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4'],
    ['keys', '/keys', 'API Keys', 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z'],
    ['end-users', '/end-users', 'End Users', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page['info']['title'] ?? 'Loxodontu') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: ['selector', '[data-theme="dark"]'],
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
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--bg-main); color: var(--text-main); }
        .font-head { font-family: 'Oswald', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }

        .no-auth .sidebar, .no-auth .topbar { display: none; }
        .no-auth .main-wrapper { margin-left: 0; margin-top: 0; min-height: 100vh; }

        .icon-sun, .icon-moon { display: none; }
        [data-theme="dark"]  .icon-moon { display: inline; }
        [data-theme="light"] .icon-sun  { display: inline; }

        .nav-link { display:flex; align-items:center; gap:0.65rem; padding:0.5rem 1.25rem; font-family:'Oswald', sans-serif; font-weight:500; font-size:0.82rem; text-transform:uppercase; letter-spacing:0.06em; text-decoration:none; border-left:2px solid transparent; color:var(--text-muted); transition:color .15s, background .15s; }
        .nav-link:hover { color: var(--text-main); background: var(--bg-hover); }
        .nav-link.active { color: var(--accent); border-left-color: var(--accent); background: var(--accent-dim); }
        .nav-section-label { display:block; font-family:'Oswald', sans-serif; font-weight:500; font-size:0.65rem; text-transform:uppercase; letter-spacing:0.15em; color:var(--text-muted); padding:0.5rem 1.25rem 0.4rem; margin-top:0.5rem; }

        .input { display:block; width:100%; background:var(--bg-hover); border:1px solid var(--border); border-radius:6px; padding:0.45rem 0.65rem; font-size:0.82rem; color:var(--text-main); font-family:'Inter', sans-serif; }
        .input:focus { outline:none; border-color:var(--accent); }
        .field-label { display:block; font-family:'Oswald', sans-serif; font-size:0.68rem; font-weight:500; text-transform:uppercase; letter-spacing:0.08em; color:var(--text-muted); }

        .btn-accent { display:inline-flex; align-items:center; gap:0.4rem; background:var(--accent); color:var(--accent-fg); font-family:'Oswald', sans-serif; font-weight:700; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; padding:0.5rem 1rem; border-radius:6px; border:none; cursor:pointer; box-shadow:0 0 16px rgba(0,240,255,0.2); }
        .btn-accent:hover { opacity:0.88; }
        .btn-accent:disabled { opacity:0.5; cursor:not-allowed; }

        .btn-ghost { display:inline-flex; align-items:center; gap:0.4rem; background:transparent; color:var(--text-main); font-family:'Oswald', sans-serif; font-weight:500; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; padding:0.4rem 0.8rem; border-radius:6px; border:1px solid var(--border); cursor:pointer; }
        .btn-ghost:hover { border-color:var(--accent); color:var(--accent); }

        .btn-ghost-danger { display:inline-flex; align-items:center; gap:0.4rem; background:transparent; color:#F85149; font-family:'Oswald', sans-serif; font-weight:500; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; padding:0.4rem 0.8rem; border-radius:6px; border:1px solid var(--border); cursor:pointer; }
        .btn-ghost-danger:hover { border-color:#F85149; background:rgba(248,81,73,0.08); }

        .tab-link { padding:0.4rem 0.85rem; border-radius:6px; font-family:'Oswald', sans-serif; font-weight:500; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; text-decoration:none; border:1px solid var(--border); color:var(--text-muted); }
        .tab-link.active { background:var(--accent-dim); color:var(--accent); border-color:var(--accent); }

        .stat-card { background:var(--bg-surface); padding:1.25rem 1.5rem; }
        .stat-label { font-family:'Oswald', sans-serif; font-weight:500; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.12em; color:var(--text-muted); margin-bottom:0.4rem; }
        .stat-value { font-family:'Oswald', sans-serif; font-weight:700; font-size:2rem; color:var(--text-main); line-height:1; }

        .modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:100; padding:1rem; }
        .modal-card { width:100%; max-width:28rem; background:var(--bg-surface); border:1px solid var(--border); border-radius:10px; padding:1.5rem; }
        .modal-title { font-family:'Oswald', sans-serif; font-weight:700; font-size:1rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-main); margin-bottom:1rem; }
    </style>
    <script>
        (function () {
            var stored = localStorage.getItem('loxo-theme');
            if (stored) document.documentElement.setAttribute('data-theme', stored);
            if (!localStorage.getItem('loxo-auth')) document.documentElement.classList.add('no-auth');
        })();
    </script>
</head>

<body class="min-h-screen">

    <aside class="sidebar fixed inset-y-0 left-0 h-full w-60 flex flex-col z-50"
        style="background:var(--bg-surface); border-right:1px solid var(--border);">
        <a href="/" class="h-14 flex items-center gap-2 px-5 no-underline" style="border-bottom:1px solid var(--border);">
            <span class="w-2 h-2 rounded-full" style="background:var(--accent); box-shadow:0 0 8px var(--accent);"></span>
            <span class="font-head font-bold text-base uppercase tracking-wider" style="color:var(--text-main);">Loxodontu</span>
        </a>

        <nav class="flex-1 overflow-y-auto py-4">
            <span class="nav-section-label">General</span>
            <a href="/dashboard" class="nav-link <?= $activeNav === 'home' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Home
            </a>
            <a href="/dashboard/projects" class="nav-link <?= $activeNav === 'projects' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
                Projects
            </a>

            <?php if ($projectId): ?>
                <span class="nav-section-label">Project</span>
                <?php foreach ($projectNavItems as [$id, $suffix, $label, $icon]): ?>
                    <a href="/dashboard/projects/<?= $projectId ?><?= $suffix ?>" class="nav-link <?= $activeNav === $id ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>" />
                        </svg>
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>

        <div class="px-5 py-4 font-head text-[0.65rem] font-medium uppercase tracking-widest" style="border-top:1px solid var(--border); color:var(--text-muted);">
            Loxodontu &copy; <?= date('Y') ?>
        </div>
    </aside>

    <header class="topbar fixed top-0 right-0 left-60 h-14 flex items-center justify-between px-6 z-40"
        style="background:var(--bg-surface); border-bottom:1px solid var(--border);">
        <span class="font-head font-bold text-sm uppercase tracking-wider" style="color:var(--text-main);">
            <?= e($page['info']['pageTitle'] ?? 'Dashboard') ?>
        </span>
        <div class="flex items-center gap-3">
            <button onclick="toggleTheme()" aria-label="Toggle theme"
                class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-head font-medium uppercase tracking-wider cursor-pointer"
                style="background:none; border:1px solid var(--border); color:var(--text-muted);">
                <svg class="icon-moon w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                </svg>
                <svg class="icon-sun w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="5" />
                    <path stroke-linecap="round" d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" />
                </svg>
                <span class="icon-moon">Light</span>
                <span class="icon-sun">Dark</span>
            </button>

            <div class="flex items-center gap-2 text-sm font-head font-medium uppercase tracking-wide" style="color:var(--text-muted);">
                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-head font-bold"
                    style="background:var(--accent-dim); border:1px solid var(--border); color:var(--accent);" id="user-initials">--</div>
                <span id="user-name-chip"></span>
            </div>

            <button onclick="platformLogout()" aria-label="Log out"
                class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-head font-medium uppercase tracking-wider cursor-pointer"
                style="background:none; border:1px solid var(--border); color:var(--text-muted);">
                Log out
            </button>
        </div>
    </header>

    <div class="main-wrapper ml-60 mt-14 min-h-[calc(100vh-3.5rem)]">
        <div id="app">
            <page></page>
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

        window.__APP = Vue.createApp({ setup() { return {}; } });
        __APP.provide('store', store);
    </script>

    <?= $page['script'] ?? '' ?>

    <script>
        __APP.mount('#app');

        function toggleTheme() {
            var el = document.documentElement;
            var next = el.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            el.setAttribute('data-theme', next);
            localStorage.setItem('loxo-theme', next);
        }
    </script>
</body>

</html>
