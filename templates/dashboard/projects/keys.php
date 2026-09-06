<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <div class="mb-6">
        <h1 class="page-title">API Keys</h1>
        <p class="page-subtitle">Keys used to authenticate requests to your project's API.</p>
    </div>

    <div v-if="revealedKey" class="card mb-4" style="border-color:var(--accent);background:var(--accent-dim);">
        <p class="text-xs font-oswald uppercase tracking-wide mb-1" style="color:var(--accent);">Save this key — it won't be shown again</p>
        <div class="flex items-center gap-2">
            <code class="text-xs font-mono flex-1 break-all" style="color:var(--text-main);">{{ revealedKey }}</code>
            <button class="btn-ghost" @click="copyToClipboard(revealedKey, $event.currentTarget)">Copy</button>
            <button class="btn-ghost" @click="revealedKey = null">Dismiss</button>
        </div>
    </div>

    <div class="listbar">
        <input v-model="search" class="input" style="max-width:20rem;" placeholder="Search keys…" />
        <div class="flex-1"></div>
        <button class="btn-accent" @click="openKeyModal()">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg> New key
        </button>
    </div>

    <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
    <template v-else>
        <div v-if="filteredKeys.length === 0" class="empty-state">
            <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="14" r="4" /><path d="M10.5 11.5 20 2" /><path d="M16 6l2 2" /><path d="M19 3l2 2" /></svg>
            <p class="empty-state-title">No API keys yet</p>
            <p class="empty-state-body">Create a key to authenticate your app's requests against this project's REST API.</p>
            <button class="btn-accent" @click="openKeyModal()">+ New key</button>
        </div>

        <div v-else class="datalist mb-2">
            <div class="datalist-head">
                <div class="datalist-cell" style="flex:2;">Name</div>
                <div class="datalist-cell" style="flex:2;">Permissions</div>
                <div class="datalist-cell">Created</div>
                <div class="datalist-cell" style="flex:0 0 6rem;"></div>
            </div>
            <div class="datalist-row" v-for="k in filteredKeys" :key="k.id">
                <div class="datalist-cell" style="flex:2;">
                    {{ k.name }}
                    <div class="font-mono text-xs" style="color:var(--text-muted);">{{ k.key_prefix }}&hellip;</div>
                </div>
                <div class="datalist-cell" style="flex:2;">
                    <span class="badge" v-for="perm in k.permissions" :key="perm" style="margin-right:0.25rem;">{{ perm }}</span>
                </div>
                <div class="datalist-cell" style="color:var(--text-muted);">
                    {{ formatDate(k.created_at) }}
                    <div class="text-xs">{{ k.expires_at ? 'expires ' + formatDate(k.expires_at) : 'no expiry' }}</div>
                </div>
                <div class="datalist-cell" style="flex:0 0 6rem;text-align:right;">
                    <button class="btn-ghost btn-icon" @click="deleteKey(k)" title="Revoke">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                    </button>
                </div>
            </div>
        </div>

        <div class="pagination">
            <span class="pagination-info">{{ total === 0 ? '0' : (offset + 1) }}&ndash;{{ Math.min(offset + limit, total) }} of {{ total }}</span>
            <div class="flex gap-2">
                <button class="btn-ghost" :disabled="offset === 0" @click="prevPage">Previous</button>
                <button class="btn-ghost" :disabled="offset + limit >= total" @click="nextPage">Next</button>
            </div>
        </div>
    </template>

    <!-- NEW KEY MODAL -->
    <div v-if="keyModal" class="modal-backdrop" @click.self="keyModal = false">
        <div class="modal-card">
            <p class="modal-title">New API Key</p>
            <label class="field-label">Name<input v-model="keyForm.name" class="input mt-1" placeholder="e.g. ci-pipeline" /></label>
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
            const permissionOptions = ['select', 'insert', 'update', 'delete', 'function', 'storage:select', 'storage:insert', 'storage:update', 'storage:delete'];

            const keys = Vue.ref([]);
            const loading = Vue.ref(true);
            const search = Vue.ref('');
            const revealedKey = Vue.ref(null);
            const total = Vue.ref(0);
            const limit = Vue.ref(25);
            const offset = Vue.ref(0);

            const keyModal = Vue.ref(false);
            const keyForm = Vue.reactive({ name: '', permissions: [], expires_at: '' });

            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });
            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            const filteredKeys = Vue.computed(() => {
                if (!search.value.trim()) return keys.value;
                const needle = search.value.trim().toLowerCase();
                return keys.value.filter((k) => k.name.toLowerCase().includes(needle));
            });

            function formatDate(v) {
                if (!v) return '—';
                return new Date(v.replace(' ', 'T')).toLocaleDateString();
            }

            async function loadKeys() {
                loading.value = true;
                try {
                    const { body, headers } = await apiFetch(`/projects/${PROJECT_ID}/keys?limit=${limit.value}&offset=${offset.value}`);
                    keys.value = body;
                    total.value = Number(headers.get('X-Total-Count') || 0);
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function prevPage() { offset.value = Math.max(0, offset.value - limit.value); loadKeys(); }
            function nextPage() { offset.value += limit.value; loadKeys(); }

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

            loadKeys();

            return {
                keys, loading, search, filteredKeys, revealedKey, total, limit, offset, permissionOptions,
                keyModal, keyForm, confirmState, formatDate,
                openKeyModal, createKey, deleteKey, prevPage, nextPage, copyToClipboard,
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
