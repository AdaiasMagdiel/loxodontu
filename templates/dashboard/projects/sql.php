<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <main class="px-8 py-8 max-w-5xl mx-auto">
        <div class="mb-6">
            <a href="/dashboard/projects" class="text-xs font-head uppercase tracking-wide no-underline" style="color:var(--text-muted);">&larr; Projects</a>
            <h1 class="font-head font-bold text-2xl uppercase tracking-wide mt-1" style="color:var(--text-main);">{{ project ? project.name : '...' }}</h1>
        </div>

        <?php include t('dashboard/projects/_tabs'); ?>

        <div class="flex items-center justify-between mb-4">
            <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">SQL Editor</p>
            <button class="btn-accent" @click="runSql" :disabled="sqlState.running">{{ sqlState.running ? 'Running' : 'Run SQL' }}</button>
        </div>

        <div class="rounded-lg overflow-hidden" style="background:var(--bg-surface); border:1px solid var(--border);">
            <div class="p-5">
                <textarea v-model="sqlState.sql" class="input font-mono min-h-[220px]" spellcheck="false" placeholder="insert into posts (title) values ('Hello');&#10;select id, title from posts;" @keydown.ctrl.enter.prevent="runSql"></textarea>
                <div class="flex items-center justify-between gap-3 mt-2 text-xs" style="color:var(--text-muted);">
                    <span>Use logical table names like posts. Separate commands by line or semicolon.</span>
                    <span class="font-mono shrink-0">Ctrl+Enter</span>
                </div>
            </div>
        </div>

        <div v-if="sqlState.results.length" class="mt-5 space-y-4">
            <div v-for="result in sqlState.results" :key="result.index" class="rounded-lg overflow-hidden" style="background:var(--bg-surface); border:1px solid var(--border);">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">Command {{ result.index }}</p>
                        <p class="text-xs font-mono" style="color:var(--text-muted);">
                            {{ result.operation }} · {{ result.affected_rows }} affected rows · {{ result.duration_ms }}ms
                            <span v-if="result.truncated"> · first {{ result.row_limit }} rows</span>
                        </p>
                    </div>
                    <div class="mt-2 text-xs font-mono rounded-md px-3 py-2 overflow-x-auto" style="background:var(--bg-hover); color:var(--text-muted);">
                        {{ result.sql }}
                    </div>
                </div>
                <div v-if="result.columns.length" class="overflow-x-auto">
                    <table class="w-full text-xs font-mono">
                        <thead>
                            <tr style="color:var(--text-muted); border-bottom:1px solid var(--border);">
                                <th v-for="col in result.columns" :key="col" class="text-left px-3 py-2">{{ col }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in result.rows" :key="idx" style="border-bottom:1px solid var(--border);">
                                <td v-for="col in result.columns" :key="col" class="px-3 py-2" style="color:var(--text-main);">{{ row[col] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="px-5 py-4 text-sm" style="color:var(--text-muted);">
                    Command executed without a result set.
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
            const project = Vue.ref(null);
            const sqlState = Vue.reactive({
                sql: "select id from posts;",
                results: [],
                running: false,
            });

            async function loadProject() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}`);
                    project.value = body;
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function runSql() {
                if (!sqlState.sql.trim()) { toast.error('SQL is required'); return; }
                sqlState.running = true;
                sqlState.results = [];
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/sql`, {
                        method: 'POST',
                        body: JSON.stringify({ sql: sqlState.sql }),
                    });
                    sqlState.results = body.results || [body];
                    toast.success(sqlState.results.length === 1 ? 'SQL executed' : 'SQL batch executed');
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    sqlState.running = false;
                }
            }

            loadProject();

            return { project, sqlState, runSql };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'SQL Editor - Loxodontu',
        'pageTitle' => 'SQL Editor',
    ],
];

include t('layouts/dashboard');
