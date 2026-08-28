<?php
ob_start();
?>
<template id="tpl-page">
    <main style="padding: 2rem; max-width: 960px;">

        <div style="margin-bottom: 2rem;">
            <p style="font-family:var(--font-head);font-weight:500;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--accent);margin-bottom:0.5rem;">
                Dashboard
            </p>
            <h1 style="font-family:var(--font-head);font-weight:700;font-size:2rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-main);line-height:1.1;">
                Welcome, {{ store.user.name }}
            </h1>
            <p style="font-size:0.875rem;color:var(--text-muted);margin-top:0.4rem;">
                Role: <span style="color:var(--text-main);font-weight:500;">{{ store.user.role }}</span>
            </p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:2rem;">
            <div class="stat-card">
                <p class="stat-label">Projects</p>
                <p class="stat-value">0</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Tables</p>
                <p class="stat-value">0</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">API Keys</p>
                <p class="stat-value">0</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">End Users</p>
                <p class="stat-value">0</p>
            </div>
        </div>

        <div style="margin-bottom:2rem;">
            <p style="font-family:var(--font-head);font-weight:500;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.15em;color:var(--text-muted);margin-bottom:1rem;">
                Quick Actions
            </p>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                <button @click="showToast()" class="btn-accent">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Project
                </button>
                <button @click="count++" class="btn-ghost">
                    Vue Reactivity: {{ count }}
                </button>
            </div>
        </div>

        <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;overflow:hidden;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.9rem 1.25rem;border-bottom:1px solid var(--border);">
                <p style="font-family:var(--font-head);font-weight:700;font-size:0.82rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-main);">
                    API Status
                </p>
                <span class="status-chip">Operational</span>
            </div>
            <div style="padding:0.75rem 1.25rem;font-family:var(--font-mono);font-size:0.78rem;line-height:1.8;">
                <div style="display:flex;gap:1rem;border-bottom:1px solid var(--border);padding-bottom:0.4rem;margin-bottom:0.4rem;">
                    <span style="color:var(--accent);min-width:36px;">GET</span>
                    <span style="color:var(--text-muted);flex:1;">/api/health</span>
                    <span style="color:#3FB950;">200 OK</span>
                </div>
                <div style="display:flex;gap:1rem;border-bottom:1px solid var(--border);padding-bottom:0.4rem;margin-bottom:0.4rem;">
                    <span style="color:#79C0FF;min-width:36px;">POST</span>
                    <span style="color:var(--text-muted);flex:1;">/api/v1/auth/register</span>
                    <span style="color:var(--border);">—</span>
                </div>
                <div style="display:flex;gap:1rem;">
                    <span style="color:#79C0FF;min-width:36px;">POST</span>
                    <span style="color:var(--text-muted);flex:1;">/api/v1/auth/login</span>
                    <span style="color:var(--border);">—</span>
                </div>
            </div>
        </div>

    </main>
</template>

<style>
    .stat-card {
        background: var(--bg-surface);
        padding: 1.25rem 1.5rem;
        border-right: 1px solid var(--border);
        border-bottom: 1px solid var(--border);
        transition: background .2s;
    }
    .stat-card:hover { background: var(--bg-hover); }
    .stat-label {
        font-family: var(--font-head);
        font-weight: 500;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: var(--text-muted);
        margin-bottom: 0.4rem;
    }
    .stat-value {
        font-family: var(--font-head);
        font-weight: 700;
        font-size: 2rem;
        color: var(--text-main);
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    .btn-accent {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: var(--accent);
        color: #0D1117;
        font-family: var(--font-head);
        font-weight: 700;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 0.55rem 1.1rem;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        transition: opacity .2s, box-shadow .2s;
        box-shadow: 0 0 16px rgba(0,240,255,0.2);
    }
    .btn-accent:hover { opacity: 0.88; box-shadow: 0 0 24px rgba(0,240,255,0.4); }

    .btn-ghost {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: transparent;
        color: var(--text-main);
        font-family: var(--font-head);
        font-weight: 500;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        padding: 0.55rem 1.1rem;
        border-radius: 6px;
        border: 1px solid var(--border);
        cursor: pointer;
        transition: border-color .2s, color .2s;
    }
    .btn-ghost:hover { border-color: var(--accent); color: var(--accent); }

    .status-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-family: var(--font-head);
        font-weight: 500;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--accent);
        background: var(--accent-dim);
        border: 1px solid rgba(0,240,255,0.2);
        padding: 0.2rem 0.6rem;
        border-radius: 100px;
    }
    .status-chip::before {
        content: '';
        display: block;
        width: 6px; height: 6px;
        border-radius: 50%;
        background: var(--accent);
        box-shadow: 0 0 6px var(--accent);
    }
</style>
<?php $body = ob_get_clean(); ?>

<?php ob_start(); ?>
<script>
    window.__APP.component('page', {
        template: '#tpl-page',
        setup() {
            const store = Vue.inject('store');
            const count = Vue.ref(0);

            const showToast = () => {
                toast.success('New project flow coming soon!');
            };

            return { store, count, showToast };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Home — Loxodontu',
        'pageTitle' => 'Home',
    ]
];

include t('layouts/dashboard');
