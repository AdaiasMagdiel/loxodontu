<?php
ob_start();
?>
<template id="tpl-page">
    <main class="px-8 py-8 max-w-5xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="font-head font-bold text-2xl uppercase tracking-wide" style="color:var(--text-main);">Projects</h1>
            <button class="btn-accent" @click="openModal()">+ New Project</button>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <div v-else-if="projects.length === 0" class="rounded-lg p-8 text-center text-sm" style="background:var(--bg-surface); border:1px solid var(--border); color:var(--text-muted);">
            No projects yet.
        </div>
        <div v-else class="rounded-lg overflow-hidden" style="border:1px solid var(--border);">
            <div v-for="p in projects" :key="p.id" class="flex flex-col"
                style="border-bottom:1px solid var(--border); background:var(--bg-surface);">
                <div class="flex items-center justify-between px-5 py-3">
                    <a :href="'/dashboard/projects/' + p.id" class="no-underline flex-1">
                        <p class="font-head font-medium text-sm" style="color:var(--text-main);">{{ p.name }}</p>
                        <p class="text-xs" style="color:var(--text-muted);">{{ p.description || 'No description' }}</p>
                    </a>
                    <span class="text-xs font-mono mr-4" style="color:var(--text-muted);">{{ p.slug }}</span>
                    <div class="flex items-center gap-2">
                        <button class="btn-ghost" @click="startRename(p)">Rename</button>
                        <button class="btn-ghost-danger" @click="deleteProject(p)">Delete</button>
                    </div>
                </div>
                <div v-if="renaming === p.id" class="px-5 pb-4 flex flex-col gap-2" style="border-top:1px solid var(--border);">
                    <label class="field-label mt-2">Name
                        <input v-model="renameForm.name" class="input mt-1" />
                    </label>
                    <label class="field-label">Description
                        <input v-model="renameForm.description" class="input mt-1" />
                    </label>
                    <div class="flex gap-2 mt-1">
                        <button class="btn-accent" @click="submitRename(p)">Save</button>
                        <button class="btn-ghost" @click="renaming = null">Cancel</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- NEW PROJECT MODAL -->
        <div v-if="modal" class="modal-backdrop" @click.self="modal = false">
            <div class="modal-card">
                <p class="modal-title">New Project</p>
                <label class="field-label">Name<input v-model="form.name" class="input mt-1" /></label>
                <label class="field-label mt-3">Description<textarea v-model="form.description" class="input mt-1" rows="3"></textarea></label>
                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn-ghost" @click="modal = false">Cancel</button>
                    <button class="btn-accent" @click="createProject">Create</button>
                </div>
            </div>
        </div>

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

            const projects = Vue.ref([]);
            const loading = Vue.ref(false);
            const modal = Vue.ref(false);
            const form = Vue.reactive({ name: '', description: '' });
            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });

            const renaming = Vue.ref(null);
            const renameForm = Vue.reactive({ name: '', description: '' });

            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            async function loadProjects() {
                loading.value = true;
                try {
                    const { body } = await apiFetch('/projects');
                    projects.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function openModal() {
                form.name = '';
                form.description = '';
                modal.value = true;
            }

            async function createProject() {
                if (!form.name.trim()) { toast.error('Name is required'); return; }
                try {
                    await apiFetch('/projects', { method: 'POST', body: JSON.stringify(form) });
                    modal.value = false;
                    toast.success('Project created');
                    await loadProjects();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteProject(p) {
                askConfirm(`Delete project "${p.name}"? This cannot be undone.`, async () => {
                    try {
                        await apiFetch(`/projects/${p.id}`, { method: 'DELETE' });
                        toast.success('Project deleted');
                        await loadProjects();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            function startRename(p) {
                renaming.value = p.id;
                renameForm.name = p.name;
                renameForm.description = p.description || '';
            }

            async function submitRename(p) {
                try {
                    await apiFetch(`/projects/${p.id}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ name: renameForm.name, description: renameForm.description }),
                    });
                    renaming.value = null;
                    toast.success('Project updated');
                    await loadProjects();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            loadProjects();

            return {
                projects, loading, modal, form, confirmState,
                renaming, renameForm,
                openModal, createProject, deleteProject, startRename, submitRename,
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
        'title'     => 'Projects — Loxodontu',
        'pageTitle' => 'Projects',
    ],
];

include t('layouts/dashboard');
