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

        <div class="flex items-center justify-between mb-4">
            <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">API Keys</p>
            <button class="btn-accent" @click="openKeyModal()">+ New Key</button>
        </div>

        <div v-if="revealedKey" class="rounded-lg p-4 mb-4" style="background:var(--accent-dim); border:1px solid var(--accent);">
            <p class="text-xs font-head uppercase tracking-wide mb-1" style="color:var(--accent);">Save this key — it won't be shown again</p>
            <div class="flex items-center gap-2">
                <code class="text-xs font-mono flex-1 break-all" style="color:var(--text-main);">{{ revealedKey }}</code>
                <button class="btn-ghost" @click="copyKey">Copy</button>
                <button class="btn-ghost" @click="revealedKey = null">Dismiss</button>
            </div>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <div v-else-if="keys.length === 0" class="rounded-lg p-8 text-center text-sm" style="background:var(--bg-surface); border:1px solid var(--border); color:var(--text-muted);">
            No API keys yet.
        </div>
        <div v-else class="rounded-lg overflow-hidden" style="border:1px solid var(--border);">
            <div v-for="k in keys" :key="k.id" class="flex items-center justify-between px-5 py-3" style="border-bottom:1px solid var(--border); background:var(--bg-surface);">
                <div>
                    <p class="font-head font-medium text-sm" style="color:var(--text-main);">{{ k.name }}</p>
                    <p class="text-xs font-mono" style="color:var(--text-muted);">{{ k.key_prefix }}… · {{ k.permissions.join(', ') }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs" style="color:var(--text-muted);">{{ k.expires_at ? 'expires ' + formatDate(k.expires_at) : 'no expiry' }}</span>
                    <button class="btn-ghost-danger" @click="deleteKey(k)">Revoke</button>
                </div>
            </div>
        </div>

        <!-- NEW KEY MODAL -->
        <div v-if="keyModal" class="modal-backdrop" @click.self="keyModal = false">
            <div class="modal-card">
                <p class="modal-title">New API Key</p>
                <label class="field-label">Name<input v-model="keyForm.name" class="input mt-1" /></label>
                <p class="field-label mt-3 mb-1">Permissions</p>
                <div class="flex gap-3 flex-wrap">
                    <label v-for="perm in permissionOptions" :key="perm" class="flex items-center gap-1 text-xs" style="color:var(--text-main);">
                        <input type="checkbox" :value="perm" v-model="keyForm.permissions" /> {{ perm }}
                    </label>
                </div>
                <label class="field-label mt-3">Expires at (optional)<input v-model="keyForm.expires_at" type="datetime-local" class="input mt-1" /></label>
                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn-ghost" @click="keyModal = false">Cancel</button>
                    <button class="btn-accent" @click="createKey">Create</button>
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

            const PROJECT_ID = <?= (int) $projectId ?>;
            const permissionOptions = ['select', 'insert', 'update', 'delete', 'function'];

            const project = Vue.ref(null);
            const keys = Vue.ref([]);
            const loading = Vue.ref(true);
            const revealedKey = Vue.ref(null);

            const keyModal = Vue.ref(false);
            const keyForm = Vue.reactive({ name: '', permissions: [], expires_at: '' });

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

            async function loadKeys() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/keys`);
                    keys.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function openKeyModal() {
                keyForm.name = '';
                keyForm.permissions = [];
                keyForm.expires_at = '';
                keyModal.value = true;
            }

            async function createKey() {
                if (!keyForm.name.trim()) { toast.error('Name is required'); return; }
                if (keyForm.permissions.length === 0) { toast.error('Select at least one permission'); return; }
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/keys`, {
                        method: 'POST',
                        body: JSON.stringify({
                            name: keyForm.name,
                            permissions: keyForm.permissions,
                            expires_at: keyForm.expires_at || null,
                        }),
                    });
                    keyModal.value = false;
                    revealedKey.value = body.key;
                    toast.success('Key created');
                    await loadKeys();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function copyKey() {
                if (!revealedKey.value) return;
                navigator.clipboard.writeText(revealedKey.value).then(() => toast.success('Copied to clipboard'));
            }

            function deleteKey(k) {
                askConfirm(`Revoke key "${k.name}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/keys/${k.id}`, { method: 'DELETE' });
                        toast.success('Key revoked');
                        await loadKeys();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadProjectHeader();
            loadKeys();

            return {
                project, keys, loading, revealedKey, permissionOptions,
                keyModal, keyForm, confirmState, formatDate,
                openKeyModal, createKey, copyKey, deleteKey,
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
        'title'     => 'API Keys — Loxodontu',
        'pageTitle' => 'API Keys',
    ],
];

include t('layouts/dashboard');
