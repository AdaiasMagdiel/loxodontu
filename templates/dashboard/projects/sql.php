<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <main>
        <div class="mb-6">
            <h1 class="page-title">SQL Editor</h1>
            <p class="page-subtitle">Run queries directly against your project's tables (logical names, e.g. <span class="font-mono">posts</span>).</p>
        </div>

        <div class="card mb-4" style="padding:0;overflow:hidden;">
            <div class="flex items-center justify-between px-4 py-2" style="border-bottom:1px solid var(--border);background:var(--bg-hover);">
                <span class="font-mono text-xs" style="color:var(--text-muted);">query.sql</span>
                <div class="flex gap-2">
                    <button class="btn-ghost" @click="copyToClipboard(sqlState.sql, $event.currentTarget)">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg> Copy
                    </button>
                    <button class="btn-accent" :disabled="sqlState.running" @click="runSql">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3" /></svg> {{ sqlState.running ? 'Running…' : 'Run' }}
                    </button>
                </div>
            </div>
            <textarea v-model="sqlState.sql" class="input font-mono" style="min-height:12rem;border:none;border-radius:0;resize:vertical;"
                spellcheck="false" placeholder="select id, title from posts;" @keydown.ctrl.enter.prevent="runSql"></textarea>
        </div>

        <div v-if="sqlState.error" class="mb-4" style="padding:0.85rem 1rem;border:1px solid var(--danger);border-radius:var(--radius-md);background:var(--danger-dim);color:var(--danger);font-size:0.85rem;">
            {{ sqlState.error }}
        </div>

        <div v-if="!sqlState.ranOnce && !sqlState.error" class="empty-state">
            <p class="empty-state-title">Run a query to see results here</p>
            <p class="empty-state-body">SELECT, INSERT, UPDATE and DELETE are supported. Separate multiple commands by line or semicolon.</p>
        </div>

        <div v-for="result in sqlState.results" :key="result.index" class="mb-5">
            <h2 class="font-head text-sm uppercase tracking-wide mb-2" style="color:var(--text-muted);">
                Results &middot; {{ result.operation }} &middot; {{ result.affected_rows }} affected row{{ result.affected_rows === 1 ? '' : 's' }} &middot; {{ result.duration_ms }}ms
                <span v-if="result.truncated">&middot; showing first {{ result.row_limit }} rows</span>
            </h2>

            <div v-if="result.columns.length === 0" class="text-sm" style="color:var(--text-muted);">
                Command executed without a result set.
            </div>
            <div v-else class="datalist">
                <div class="datalist-head">
                    <div v-for="col in result.columns" :key="col" class="datalist-cell font-mono">{{ col }}</div>
                </div>
                <div v-for="(row, idx) in result.rows" :key="idx" class="datalist-row">
                    <div v-for="col in result.columns" :key="col" class="datalist-cell font-mono">{{ row[col] === null ? '' : row[col] }}</div>
                </div>
            </div>
        </div>
    </main>
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
            const sqlState = Vue.reactive({
                sql: '',
                results: [],
                running: false,
                error: null,
                ranOnce: false,
            });

            async function runSql() {
                if (!sqlState.sql.trim()) { toast.error('SQL is required'); return; }
                sqlState.running = true;
                sqlState.error = null;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/sql`, {
                        method: 'POST',
                        body: JSON.stringify({ sql: sqlState.sql }),
                    });
                    sqlState.results = body.results || [];
                    sqlState.ranOnce = true;
                    toast.success(sqlState.results.length === 1 ? 'Query executed' : `${sqlState.results.length} commands executed`);
                } catch (e) {
                    sqlState.error = e.message;
                    sqlState.results = [];
                } finally {
                    sqlState.running = false;
                }
            }

            return { sqlState, runSql, copyToClipboard };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'SQL Editor — Loxodontu',
        'pageTitle' => 'SQL Editor',
    ],
];

include t('layouts/dashboard');
