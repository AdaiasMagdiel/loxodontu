<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <div class="mb-6">
        <h1 class="page-title">Project Settings</h1>
        <p class="page-subtitle">General information and destructive actions for this project.</p>
    </div>

    <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
    <template v-else>
        <div class="card mb-4" style="max-width:32rem;">
            <label class="field-label mb-1 block">Project name</label>
            <input v-model="form.name" class="input mb-3" />
            <label class="field-label mb-1 block">Description</label>
            <textarea v-model="form.description" class="input" style="min-height:5rem;"></textarea>
        </div>

        <div class="editor-savebar mb-6" style="max-width:32rem;">
            <span class="text-xs" style="color:var(--text-muted);"></span>
            <button class="btn-accent" @click="save">Save changes</button>
        </div>

        <div class="card mb-4" style="max-width:32rem;">
            <label class="field-label mb-1 block">Project ID</label>
            <p class="flex items-center gap-2 font-mono text-sm">
                {{ project.id }}
                <button class="btn-ghost btn-icon" @click="copyToClipboard(project.id, $event.currentTarget)">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
                </button>
            </p>
        </div>

        <div class="card" style="max-width:32rem;border-color:var(--danger);">
            <p class="font-medium mb-1" style="color:var(--danger);">Danger zone</p>
            <p class="text-sm mb-3" style="color:var(--text-muted);">Deleting this project is permanent and cannot be undone.</p>
            <button class="btn-ghost-danger" @click="deleteProject">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg> Delete project
            </button>
        </div>
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
            const loading = Vue.ref(true);
            const form = Vue.reactive({ name: '', description: '' });

            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });
            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            async function loadProject() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}`);
                    project.value = body;
                    form.name = body.name;
                    form.description = body.description || '';
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            async function save() {
                if (!form.name.trim()) { toast.error('Name is required'); return; }

                const patch = {};
                if (form.name !== project.value.name) patch.name = form.name;
                if (form.description !== (project.value.description || '')) patch.description = form.description;

                if (Object.keys(patch).length === 0) { toast.info('Nothing to save'); return; }

                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}`, {
                        method: 'PATCH',
                        body: JSON.stringify(patch),
                    });
                    project.value = { ...project.value, ...body };
                    toast.success('Project settings saved');
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteProject() {
                askConfirm(`Delete project "${project.value.name}"? This cannot be undone.`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}`, { method: 'DELETE' });
                        location.href = '/dashboard/projects';
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadProject();

            return { project, loading, form, confirmState, save, deleteProject, copyToClipboard };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Project Settings — Loxodontu',
        'pageTitle' => 'Project Settings',
    ],
];

include t('layouts/dashboard');
