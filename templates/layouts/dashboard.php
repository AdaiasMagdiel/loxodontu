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
                    colors: {
                        'canvas':   '#0D1117',
                        'surface':  '#161B22',
                        'surface2': '#1C2230',
                        'border':   '#21262D',
                        'muted':    '#8B949E',
                        'accent':   '#00F0FF',
                    },
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
            --accent-dim: rgba(0,240,255,0.10);
            --border:     #21262D;
            --sidebar-w:  240px;
            --topbar-h:   56px;
            --font-head:  'Oswald', sans-serif;
            --font-body:  'Inter', sans-serif;
            --font-mono:  'JetBrains Mono', monospace;
        }

        [data-theme="light"] {
            --bg-main:    #F0F4F8;
            --bg-surface: #FFFFFF;
            --bg-hover:   #F5F8FC;
            --text-main:  #121212;
            --text-muted: #556270;
            --accent:     #0088AA;
            --accent-dim: rgba(0,136,170,0.10);
            --border:     #D1D9E0;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-body);
            background-color: var(--bg-main);
            color: var(--text-main);
            min-height: 100vh;
        }

        /* ── SIDEBAR ─────────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: var(--sidebar-w);
            background: var(--bg-surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            z-index: 50;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0 1.25rem;
            height: var(--topbar-h);
            border-bottom: 1px solid var(--border);
            text-decoration: none;
        }

        .brand-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 8px var(--accent);
            flex-shrink: 0;
        }

        .brand-name {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-main);
        }

        .sidebar-nav {
            flex: 1;
            padding: 1rem 0;
            overflow-y: auto;
        }

        .nav-section-label {
            font-family: var(--font-head);
            font-weight: 500;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--text-muted);
            padding: 0.5rem 1.25rem 0.4rem;
            margin-top: 0.5rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.55rem 1.25rem;
            font-family: var(--font-head);
            font-weight: 500;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            text-decoration: none;
            transition: color .15s, background .15s;
            border-left: 2px solid transparent;
        }

        .nav-item:hover {
            color: var(--text-main);
            background: var(--bg-hover);
        }

        .nav-item.active {
            color: var(--accent);
            border-left-color: var(--accent);
            background: var(--accent-dim);
        }

        .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }

        .sidebar-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--border);
            font-family: var(--font-head);
            font-size: 0.65rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
        }

        /* ── TOPBAR ──────────────────────────────────────── */
        .topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-w);
            right: 0;
            height: var(--topbar-h);
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            z-index: 40;
        }

        .page-title {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-main);
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            background: none;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.3rem 0.65rem;
            cursor: pointer;
            color: var(--text-muted);
            font-family: var(--font-head);
            font-weight: 500;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            transition: border-color .2s, color .2s;
        }

        .theme-toggle:hover { border-color: var(--accent); color: var(--accent); }
        .theme-toggle svg { width: 13px; height: 13px; }

        .icon-sun, .icon-moon { display: none; }
        [data-theme="dark"]  .icon-moon { display: inline; }
        [data-theme="light"] .icon-sun  { display: inline; }

        .user-chip {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-family: var(--font-head);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .user-avatar {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--accent-dim);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            color: var(--accent);
            font-family: var(--font-head);
            font-weight: 700;
        }

        /* ── MAIN AREA ───────────────────────────────────── */
        .main-wrapper {
            margin-left: var(--sidebar-w);
            margin-top: var(--topbar-h);
            min-height: calc(100vh - var(--topbar-h));
            background: var(--bg-main);
        }
    </style>
    <script>
        (function () {
            var stored = localStorage.getItem('loxo-theme');
            if (stored) document.documentElement.setAttribute('data-theme', stored);
        })();
    </script>
</head>

<body>

    <aside class="sidebar">
        <a href="/" class="sidebar-brand">
            <span class="brand-dot"></span>
            <span class="brand-name">Loxodontu</span>
        </a>

        <nav class="sidebar-nav">
            <span class="nav-section-label">General</span>

            <a href="/dashboard" class="nav-item active">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Home
            </a>

            <span class="nav-section-label">Platform</span>

            <a href="#" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                Projects
            </a>

            <a href="#" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                Auth
            </a>

            <a href="#" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                </svg>
                Database
            </a>

            <a href="#" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
                API Keys
            </a>

            <a href="#" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                RLS Policies
            </a>
        </nav>

        <div class="sidebar-footer">
            Loxodontu &copy; <?= date('Y') ?>
        </div>
    </aside>

    <header class="topbar">
        <span class="page-title"><?= e($page['info']['pageTitle'] ?? 'Dashboard') ?></span>
        <div class="topbar-actions">
            <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle theme">
                <svg class="icon-moon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>
                </svg>
                <svg class="icon-sun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="5"/>
                    <path stroke-linecap="round" d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                </svg>
                <span class="icon-moon">Light</span>
                <span class="icon-sun">Dark</span>
            </button>

            <div class="user-chip">
                <div class="user-avatar" id="user-initials">--</div>
                <span id="user-name-chip"></span>
            </div>
        </div>
    </header>

    <div class="main-wrapper">
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
            user: {
                name: 'Adaías Magdiel',
                role: 'Developer'
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            var name = store.user.name;
            var parts = name.trim().split(' ');
            var initials = parts.length > 1
                ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
                : parts[0].slice(0, 2).toUpperCase();
            document.getElementById('user-initials').textContent = initials;
            document.getElementById('user-name-chip').textContent = store.user.name;
        });

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
