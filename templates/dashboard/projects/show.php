<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <main class="px-8 py-8 max-w-5xl mx-auto">
        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <div v-else-if="!project" class="text-sm" style="color:var(--text-muted);">Project not found.</div>
        <template v-else>
            <div class="mb-6">
                <a href="/dashboard/projects" class="text-xs font-head uppercase tracking-wide no-underline" style="color:var(--text-muted);">&larr; Projects</a>
                <h1 class="font-head font-bold text-2xl uppercase tracking-wide mt-1" style="color:var(--text-main);">{{ project.name }}</h1>
                <p class="text-xs font-mono mt-1" style="color:var(--text-muted);">{{ project.slug }}</p>
            </div>

            <?php include t('dashboard/projects/_tabs'); ?>

            <div class="grid gap-px rounded-lg overflow-hidden mb-6" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); border:1px solid var(--border); background:var(--border);">
                <div class="stat-card">
                    <p class="stat-label">Tables</p>
                    <p class="stat-value">{{ project.tables.length }}</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Created</p>
                    <p class="stat-value text-lg">{{ formatDate(project.created_at) }}</p>
                </div>
            </div>

            <div class="rounded-lg p-5 mb-6" style="background:var(--bg-surface); border:1px solid var(--border);">
                <p class="font-head font-medium text-xs uppercase tracking-widest mb-2" style="color:var(--text-muted);">Description</p>
                <p class="text-sm" style="color:var(--text-main);">{{ project.description || 'No description provided.' }}</p>
            </div>

            <button class="btn-ghost-danger" @click="deleteProject()">Delete project</button>
        </template>

        <!-- CONFIRM DIALOG -->
        <div v-if="confirmState.show" class="modal-backdrop" @click.self="confirmState.show = false">
            <div class="modal-card max-w-xs">
                <p class="text-sm mb-4" style="color:var(--text-main);">{{ confirmState.message }}</p>
                <div class="flex justify-end gap-2">
                    <button class="btn-ghost" @click="confirmState.show = false">Cancel</button>
                    <button class="btn-ghost-danger" @click="confirmState.run()">Confirm</button>
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

            const PROJECT_ID = <?= (int) $projectId ?>;

            const project = Vue.ref(null);
            const loading = Vue.ref(true);
            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });

            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            function formatDate(v) {
                if (!v) return '—';
                return new Date(v.replace(' ', 'T')).toLocaleDateString();
            }

            async function loadProject() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}`);
                    project.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function deleteProject() {
                askConfirm(`Delete project "${project.value.name}"? This cannot be undone.`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}`, { method: 'DELETE' });
                        toast.success('Project deleted');
                        location.href = '/dashboard/projects';
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadProject();

            return { project, loading, confirmState, formatDate, deleteProject };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Project — Loxodontu',
        'pageTitle' => 'Project Overview',
    ],
];

include t('layouts/dashboard');
