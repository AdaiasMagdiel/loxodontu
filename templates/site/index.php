<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loxodontu | Self-Hostable BaaS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main:    #0D1117;
            --bg-card:    #161B22;
            --bg-card-hover: #1C2230;
            --text-main:  #FFFFFF;
            --text-muted: #8B949E;
            --accent:     #00F0FF;
            --accent-dim: rgba(0, 240, 255, 0.12);
            --border:     #21262D;
            --font-head:  'Oswald', sans-serif;
            --font-body:  'Inter', sans-serif;
            --font-mono:  'JetBrains Mono', monospace;
        }

        [data-theme="light"] {
            --bg-main:    #F0F4F8;
            --bg-card:    #FFFFFF;
            --bg-card-hover: #F5F8FC;
            --text-main:  #121212;
            --text-muted: #556270;
            --accent:     #0088AA;
            --accent-dim: rgba(0, 136, 170, 0.10);
            --border:     #D1D9E0;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background-color: var(--bg-main);
            color: var(--text-main);
            font-family: var(--font-body);
            min-height: 100vh;
            transition: background-color .25s, color .25s;
            line-height: 1.6;
        }

        /* ── NAV ─────────────────────────────────────────── */
        nav {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            height: 60px;
            border-bottom: 1px solid var(--border);
            background-color: var(--bg-main);
        }

        .nav-brand {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 1.25rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-main);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .nav-brand .accent-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--accent);
            box-shadow: 0 0 8px var(--accent);
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .nav-link {
            font-family: var(--font-head);
            font-weight: 500;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            text-decoration: none;
            transition: color .2s;
        }

        .nav-link:hover { color: var(--accent); }

        .theme-toggle {
            background: none;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.35rem 0.6rem;
            cursor: pointer;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            font-family: var(--font-head);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            transition: border-color .2s, color .2s;
        }

        .theme-toggle:hover { border-color: var(--accent); color: var(--accent); }
        .theme-toggle svg { width: 14px; height: 14px; }

        /* ── HERO ────────────────────────────────────────── */
        .hero {
            padding: 140px 2rem 80px;
            max-width: 900px;
            margin: 0 auto;
            text-align: center;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 100px;
            padding: 0.3rem 0.9rem;
            font-family: var(--font-head);
            font-weight: 500;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--accent);
            background: var(--accent-dim);
            margin-bottom: 2rem;
        }

        .hero-badge::before {
            content: '';
            display: block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--accent);
            box-shadow: 0 0 6px var(--accent);
        }

        .hero h1 {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: clamp(2.8rem, 7vw, 5.5rem);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            line-height: 1.05;
            color: var(--text-main);
            margin-bottom: 1.5rem;
            text-wrap: balance;
        }

        .hero h1 .accent { color: var(--accent); }

        .hero-sub {
            font-size: 1.1rem;
            color: var(--text-muted);
            max-width: 560px;
            margin: 0 auto 2.5rem;
        }

        .hero-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background-color: var(--accent);
            color: #0D1117;
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.75rem 1.75rem;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: opacity .2s, box-shadow .2s;
            box-shadow: 0 0 20px rgba(0,240,255,0.25);
        }

        .btn-primary:hover { opacity: 0.9; box-shadow: 0 0 32px rgba(0,240,255,0.45); }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: transparent;
            color: var(--text-main);
            font-family: var(--font-head);
            font-weight: 500;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.75rem 1.75rem;
            border-radius: 6px;
            text-decoration: none;
            border: 1px solid var(--border);
            cursor: pointer;
            transition: border-color .2s, color .2s;
        }

        .btn-secondary:hover { border-color: var(--accent); color: var(--accent); }

        /* ── CODE BLOCK ──────────────────────────────────── */
        .hero-code {
            margin: 3rem auto 0;
            max-width: 480px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            text-align: left;
        }

        .hero-code .code-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        .code-bar .dot { width: 10px; height: 10px; border-radius: 50%; }
        .code-bar .dot-r { background: #FF5F57; }
        .code-bar .dot-y { background: #FEBC2E; }
        .code-bar .dot-g { background: #28C840; }

        .hero-code pre {
            padding: 1.25rem 1.5rem;
            font-family: var(--font-mono);
            font-size: 0.82rem;
            line-height: 1.7;
            color: var(--text-muted);
            overflow-x: auto;
        }

        .hero-code pre .cmd { color: var(--accent); }
        .hero-code pre .comment { color: #444D56; }

        /* ── DIVIDER ─────────────────────────────────────── */
        .section-divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 0;
        }

        /* ── FEATURES ────────────────────────────────────── */
        .features {
            padding: 80px 2rem;
            max-width: 960px;
            margin: 0 auto;
        }

        .section-label {
            font-family: var(--font-head);
            font-weight: 500;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--accent);
            margin-bottom: 0.75rem;
        }

        .section-title {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: clamp(1.6rem, 3.5vw, 2.4rem);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-main);
            margin-bottom: 3rem;
            text-wrap: balance;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .feature-card {
            background: var(--bg-card);
            padding: 2rem;
            transition: background .2s;
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .feature-card:hover { background: var(--bg-card-hover); }

        .feature-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: var(--accent-dim);
            border: 1px solid rgba(0,240,255,0.2);
            margin-bottom: 1.25rem;
            color: var(--accent);
        }

        [data-theme="light"] .feature-icon {
            border-color: rgba(0,136,170,0.25);
        }

        .feature-icon svg { width: 20px; height: 20px; }

        .feature-card h3 {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-main);
            margin-bottom: 0.6rem;
        }

        .feature-card p {
            font-size: 0.875rem;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* ── FOOTER ──────────────────────────────────────── */
        footer {
            border-top: 1px solid var(--border);
            padding: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            max-width: 960px;
            margin: 0 auto;
        }

        .footer-brand {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
        }

        .footer-link {
            font-family: var(--font-head);
            font-weight: 500;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            text-decoration: none;
            transition: color .2s;
        }

        .footer-link:hover { color: var(--accent); }

        /* ── ICON HELPERS ────────────────────────────────── */
        .icon-sun, .icon-moon { display: none; }
        [data-theme="dark"]  .icon-moon { display: inline; }
        [data-theme="light"] .icon-sun  { display: inline; }
    </style>
</head>

<body>

    <nav>
        <a href="/" class="nav-brand">
            <span class="accent-dot"></span>
            Loxodontu
        </a>
        <div class="nav-right">
            <a href="https://github.com/adaiasmagdiel/loxodontu" class="nav-link" target="_blank" rel="noreferrer">Source</a>
            <a href="/dashboard" class="nav-link">Dashboard</a>
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
        </div>
    </nav>

    <section class="hero">
        <div class="hero-badge">Open Source &middot; Self-Hostable</div>

        <h1>
            Your Own<br>
            <span class="accent">Backend</span><br>
            Infrastructure
        </h1>

        <p class="hero-sub">
            A lightweight, self-hostable Backend-as-a-Service built with PHP.
            Auth, database, and REST API — ready in minutes.
        </p>

        <div class="hero-actions">
            <a href="/dashboard" class="btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
                Open Dashboard
            </a>
            <a href="https://github.com/adaiasmagdiel/loxodontu" class="btn-secondary" target="_blank" rel="noreferrer">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
                </svg>
                View Source
            </a>
        </div>

        <div class="hero-code">
            <div class="code-bar">
                <span class="dot dot-r"></span>
                <span class="dot dot-y"></span>
                <span class="dot dot-g"></span>
            </div>
            <pre><span class="comment"># Clone and start</span>
<span class="cmd">git clone</span> github.com/adaiasmagdiel/loxodontu
<span class="cmd">cd</span> loxodontu
<span class="cmd">composer install</span>
<span class="cmd">php -S</span> localhost:8000</pre>
        </div>
    </section>

    <hr class="section-divider">

    <section class="features">
        <p class="section-label">What's included</p>
        <h2 class="section-title">Everything You Need</h2>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <h3>Authentication</h3>
                <p>Register, login and logout with platform auth tokens. Secure session management out of the box.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                    </svg>
                </div>
                <h3>Database</h3>
                <p>Managed schema per project with tables, columns and automatic migrations via PHP.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h3>REST API</h3>
                <p>Auto-generated REST endpoints for every table. Query, insert, update and delete your data instantly.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <h3>RLS Policies</h3>
                <p>Row-level security policies per project. Control data access at the row level for end users.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <h3>API Keys</h3>
                <p>Generate and manage API keys per project. Revoke anytime for granular access control.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                    </svg>
                </div>
                <h3>Self-Hostable</h3>
                <p>Run it on your own server. No vendor lock-in, no subscription fees. Pure PHP, easy to deploy.</p>
            </div>
        </div>
    </section>

    <hr class="section-divider">

    <footer>
        <span class="footer-brand">Loxodontu &copy; <?= date('Y') ?></span>
        <div style="display:flex; gap: 1.5rem;">
            <a href="https://github.com/adaiasmagdiel/loxodontu" class="footer-link" target="_blank" rel="noreferrer">GitHub</a>
            <a href="/dashboard" class="footer-link">Dashboard</a>
        </div>
    </footer>

    <script>
        (function () {
            var stored = localStorage.getItem('loxo-theme');
            if (stored) document.documentElement.setAttribute('data-theme', stored);
        })();

        function toggleTheme() {
            var el = document.documentElement;
            var next = el.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            el.setAttribute('data-theme', next);
            localStorage.setItem('loxo-theme', next);
        }
    </script>

</body>
</html>
