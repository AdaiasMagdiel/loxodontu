<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <main class="px-8 py-8 max-w-5xl mx-auto">
        <div class="mb-6">
            <a href="/dashboard/projects" class="text-xs font-head uppercase tracking-wide no-underline" style="color:var(--text-muted);">&larr; Projects</a>
            <h1 class="font-head font-bold text-2xl uppercase tracking-wide mt-1" style="color:var(--text-main);">{{ project ? project.name : '…' }}</h1>
        </div>

        <?php include t('dashboard/projects/_tabs'); ?>

        <p class="font-head font-medium text-xs uppercase tracking-widest mb-4" style="color:var(--text-muted);">End Users</p>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <div v-else-if="endUsers.length === 0" class="rounded-lg p-8 text-center text-sm" style="background:var(--bg-surface); border:1px solid var(--border); color:var(--text-muted);">
            No end users have registered for this project yet.
        </div>
        <div v-else class="rounded-lg overflow-hidden" style="border:1px solid var(--border);">
            <div v-for="u in endUsers" :key="u.id" class="flex items-center justify-between px-5 py-3" style="border-bottom:1px solid var(--border); background:var(--bg-surface);">
                <div>
                    <p class="font-head font-medium text-sm" style="color:var(--text-main);">{{ u.email }}</p>
                    <p class="text-xs" style="color:var(--text-muted);">Joined {{ formatDate(u.created_at) }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <input v-model="roleDrafts[u.id]" placeholder="role" class="input w-32" />
                    <button class="btn-ghost" @click="saveRole(u)">Save</button>
                    <button class="btn-ghost-danger" @click="deleteEndUser(u)">Remove</button>
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

            const PROJECT_ID = <?= json_encode((string) $projectId) ?>;

            const project = Vue.ref(null);
            const endUsers = Vue.ref([]);
            const loading = Vue.ref(true);
            const roleDrafts = Vue.reactive({});

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

            async function loadProjectHeader() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}`);
                    project.value = body;
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function loadEndUsers() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/end-users`);
                    endUsers.value = body;
                    body.forEach(u => { roleDrafts[u.id] = u.role || ''; });
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            async function saveRole(u) {
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/end-users/${u.id}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ role: roleDrafts[u.id]?.trim() || null }),
                    });
                    toast.success('Role updated');
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteEndUser(u) {
                askConfirm(`Remove end user "${u.email}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/end-users/${u.id}`, { method: 'DELETE' });
                        toast.success('End user removed');
                        await loadEndUsers();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadProjectHeader();
            loadEndUsers();

            return { project, endUsers, loading, roleDrafts, confirmState, formatDate, saveRole, deleteEndUser };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'End Users — Loxodontu',
        'pageTitle' => 'End Users',
    ],
];

include t('layouts/dashboard');
