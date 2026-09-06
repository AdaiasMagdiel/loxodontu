<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <div class="mb-6">
        <h1 class="page-title">{{ project ? project.name : '…' }}</h1>
        <p class="page-subtitle">{{ project ? 'created ' + formatDate(project.created_at) : '…' }}</p>
    </div>

    <div v-if="statsLoading" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="card" v-for="n in 4" :key="n"><div class="skeleton" style="height:2.5rem;"></div></div>
    </div>
    <div v-else class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="card">
            <p class="text-xs font-oswald uppercase tracking-wide" style="color:var(--text-muted);">Tables</p>
            <p class="text-2xl font-oswald font-semibold mt-2">{{ stats.tables }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-oswald uppercase tracking-wide" style="color:var(--text-muted);">Storage used</p>
            <p class="text-2xl font-oswald font-semibold mt-2">{{ formatBytes(stats.storage_bytes) }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-oswald uppercase tracking-wide" style="color:var(--text-muted);">End Users</p>
            <p class="text-2xl font-oswald font-semibold mt-2">{{ stats.end_users }}</p>
        </div>
        <div class="card">
            <p class="text-xs font-oswald uppercase tracking-wide" style="color:var(--text-muted);">Cron Jobs</p>
            <p class="text-2xl font-oswald font-semibold mt-2">{{ stats.cron_jobs }}</p>
        </div>
    </div>

    <div class="card mb-8">
        <div class="card-header">
            <p class="font-oswald text-sm uppercase tracking-wide" style="color:var(--text-main);">Connect to your project</p>
            <a :href="`/dashboard/projects/${PROJECT_ID}/keys`" class="text-xs" style="color:var(--accent);text-decoration:none;">Manage keys &rarr;</a>
        </div>
        <div class="kv-row">
            <span class="kv-label">Project ID</span>
            <span class="kv-value">{{ PROJECT_ID }}</span>
            <button class="btn-ghost btn-icon" @click="copyToClipboard(PROJECT_ID, $event.currentTarget)">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
            </button>
        </div>
        <div class="kv-row">
            <span class="kv-label">API base URL</span>
            <span class="kv-value">{{ apiBaseUrl }}</span>
            <button class="btn-ghost btn-icon" @click="copyToClipboard(apiBaseUrl, $event.currentTarget)">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
            </button>
        </div>

        <template v-if="!keysLoading">
            <div v-if="keys.length === 0" class="text-xs mt-3" style="color:var(--text-muted);">
                No API keys yet — create one to authenticate requests from your app.
            </div>
            <div v-for="k in keys" :key="k.id" class="kv-row">
                <span class="kv-label">{{ k.name }}</span>
                <span class="kv-value">{{ k.key_prefix }}&hellip;</span>
                <span class="badge" v-for="perm in k.permissions.slice(0, 1)" :key="perm">{{ perm }}</span>
            </div>
        </template>
    </div>

    <h2 class="font-oswald text-sm uppercase tracking-wide mb-3" style="color:var(--text-muted);">Jump to</h2>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-8">
        <a :href="`/dashboard/projects/${PROJECT_ID}/table-editor`" class="card card-hover flex items-center gap-3">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M3 9h18" /><path d="M9 9v11" /></svg><span class="font-medium">Table Editor</span>
        </a>
        <a :href="`/dashboard/projects/${PROJECT_ID}/storage`" class="card card-hover flex items-center gap-3">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7 12 3l9 4-9 4-9-4Z" /><path d="M3 7v10l9 4 9-4V7" /><path d="M12 11v10" /></svg><span class="font-medium">Storage</span>
        </a>
        <a :href="`/dashboard/projects/${PROJECT_ID}/auth/users`" class="card card-hover flex items-center gap-3">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7" /></svg><span class="font-medium">Auth</span>
        </a>
        <a :href="`/dashboard/projects/${PROJECT_ID}/functions`" class="card card-hover flex items-center gap-3">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="8 4 4 12 8 20" /><polyline points="16 4 20 12 16 20" /></svg><span class="font-medium">Functions</span>
        </a>
        <a :href="`/dashboard/projects/${PROJECT_ID}/cron-jobs`" class="card card-hover flex items-center gap-3">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 3" /></svg><span class="font-medium">Cron Jobs</span>
        </a>
        <a :href="`/dashboard/projects/${PROJECT_ID}/keys`" class="card card-hover flex items-center gap-3">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="14" r="4" /><path d="M10.5 11.5 20 2" /><path d="M16 6l2 2" /><path d="M19 3l2 2" /></svg><span class="font-medium">API Keys</span>
        </a>
    </div>
</template>
<?php $body = ob_get_clean(); ?>

<?php ob_start(); ?>
<script>
    window.__APP.component('page', {
        template: '#tpl-page',
        setup() {
            const store = Vue.inject('store');
            if (!store.auth) { location.href = '/dashboard'; return {}; }

            const PROJECT_ID = <?= json_encode((string) $projectId) ?>;
            const project = Vue.inject('project');

            const stats = Vue.ref({});
            const statsLoading = Vue.ref(true);
            const keys = Vue.ref([]);
            const keysLoading = Vue.ref(true);
            const apiBaseUrl = `${location.origin}/api/v1/${PROJECT_ID}`;

            function formatDate(v) {
                if (!v) return '…';
                return new Date(v.replace(' ', 'T')).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            }

            function formatBytes(bytes) {
                bytes = Number(bytes) || 0;
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
                return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB';
            }

            async function loadStats() {
                statsLoading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/stats`);
                    stats.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    statsLoading.value = false;
                }
            }

            async function loadKeys() {
                keysLoading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/keys`);
                    keys.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    keysLoading.value = false;
                }
            }

            loadStats();
            loadKeys();

            return {
                PROJECT_ID, project, stats, statsLoading, keys, keysLoading, apiBaseUrl,
                formatDate, formatBytes, copyToClipboard,
            };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Overview — Loxodontu',
        'pageTitle' => 'Overview',
    ],
];

include t('layouts/dashboard');
