<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <div class="mb-6">
        <h1 class="page-title">Database</h1>
        <p class="page-subtitle">Row-level security policies across all tables.</p>
    </div>

    <div class="listbar">
        <input v-model="search" class="input" style="max-width:20rem;" placeholder="Search policies…" />
        <select v-model="tableFilter" class="input" style="max-width:12rem;">
            <option value="">All tables</option>
            <option v-for="t in tables" :key="t.id" :value="t.name">{{ t.name }}</option>
        </select>
        <div class="flex-1"></div>
        <button class="btn-accent" @click="openPolicyModal()">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg> New policy
        </button>
    </div>

    <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
    <template v-else>
        <div v-if="filteredPolicies.length === 0" class="empty-state">
            <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><ellipse cx="12" cy="5" rx="8" ry="3" /><path d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5" /><path d="M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3" /></svg>
            <p class="empty-state-title">No RLS policies yet</p>
            <p class="empty-state-body">Policies restrict which rows a request can see or change via REST passthrough.</p>
            <button class="btn-accent" @click="openPolicyModal()">+ New policy</button>
        </div>

        <div v-else class="datalist mb-2">
            <div class="datalist-head">
                <div class="datalist-cell" style="flex:2;">Policy</div>
                <div class="datalist-cell">Table</div>
                <div class="datalist-cell">Command</div>
                <div class="datalist-cell">Status</div>
                <div class="datalist-cell" style="flex:0 0 4rem;"></div>
            </div>
            <div class="datalist-row" v-for="p in filteredPolicies" :key="p.id">
                <div class="datalist-cell" style="flex:2;">{{ p.name }}</div>
                <div class="datalist-cell font-mono">{{ p.table_name }}</div>
                <div class="datalist-cell"><span class="badge" :class="commandBadgeClass(p.operation)">{{ p.operation }}</span></div>
                <div class="datalist-cell"><span class="badge" :class="p.enabled ? 'badge-success' : ''">{{ p.enabled ? 'Enabled' : 'Disabled' }}</span></div>
                <div class="datalist-cell" style="flex:0 0 4rem;text-align:right;">
                    <button class="btn-ghost btn-icon" @click="deletePolicy(p)" title="Delete">
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

    <!-- NEW POLICY MODAL -->
    <div v-if="policyModal" class="modal-backdrop" @click.self="policyModal = false">
        <div class="modal-card">
            <p class="modal-title">New Policy</p>
            <label class="field-label">Table
                <select v-model="policyForm.table_id" class="input mt-1">
                    <option value="" disabled>Select a table&hellip;</option>
                    <option v-for="t in tables" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </label>
            <label class="field-label mt-3">Name<input v-model="policyForm.name" class="input mt-1" placeholder="e.g. Users can view own row" /></label>
            <label class="field-label mt-3">Operation
                <select v-model="policyForm.operation" class="input mt-1">
                    <option v-for="op in operations" :key="op" :value="op">{{ op }}</option>
                </select>
            </label>
            <label class="field-label mt-3">Expression<textarea v-model="policyForm.expression" rows="3" class="input mt-1 font-mono" placeholder="owner_id = $auth.id"></textarea></label>
            <label class="flex items-center gap-2 text-xs mt-3" style="color:var(--text-main);">
                <input type="checkbox" v-model="policyForm.enabled" /> Enabled
            </label>
            <div class="flex justify-end gap-2 mt-4">
                <button class="btn-ghost" @click="policyModal = false">Cancel</button>
                <button class="btn-accent" @click="createPolicy">Create</button>
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
            const operations = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALL'];

            const tables = Vue.ref([]);
            const policies = Vue.ref([]);
            const loading = Vue.ref(true);
            const search = Vue.ref('');
            const tableFilter = Vue.ref('');
            const total = Vue.ref(0);
            const limit = Vue.ref(25);
            const offset = Vue.ref(0);

            const policyModal = Vue.ref(false);
            const policyForm = Vue.reactive({ table_id: '', name: '', operation: 'SELECT', expression: '', enabled: true });

            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });
            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            const filteredPolicies = Vue.computed(() => {
                return policies.value.filter((p) => {
                    if (tableFilter.value && p.table_name !== tableFilter.value) return false;
                    if (!search.value.trim()) return true;
                    const needle = search.value.trim().toLowerCase();
                    return p.name.toLowerCase().includes(needle)
                        || p.table_name.toLowerCase().includes(needle)
                        || p.expression.toLowerCase().includes(needle);
                });
            });

            function commandBadgeClass(op) {
                if (op === 'SELECT') return 'badge-accent';
                if (op === 'DELETE') return 'badge-danger';
                if (op === 'INSERT' || op === 'UPDATE' || op === 'ALL') return 'badge-warning';
                return '';
            }

            async function loadTables() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/tables?limit=100`);
                    tables.value = body;
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function loadPolicies() {
                loading.value = true;
                try {
                    const { body, headers } = await apiFetch(`/projects/${PROJECT_ID}/rls-policies?limit=${limit.value}&offset=${offset.value}`);
                    policies.value = body;
                    total.value = Number(headers.get('X-Total-Count') || 0);
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function prevPage() { offset.value = Math.max(0, offset.value - limit.value); loadPolicies(); }
            function nextPage() { offset.value += limit.value; loadPolicies(); }

            function openPolicyModal() {
                policyForm.table_id = tables.value[0]?.id ?? '';
                policyForm.name = '';
                policyForm.operation = 'SELECT';
                policyForm.expression = '';
                policyForm.enabled = true;
                policyModal.value = true;
            }

            async function createPolicy() {
                if (!policyForm.table_id) { toast.error('Select a table'); return; }
                if (!policyForm.name.trim()) { toast.error('Name is required'); return; }
                if (!policyForm.expression.trim()) { toast.error('Expression is required'); return; }
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/tables/${policyForm.table_id}/rls-policies`, {
                        method: 'POST',
                        body: JSON.stringify({
                            name: policyForm.name,
                            operation: policyForm.operation,
                            expression: policyForm.expression,
                            enabled: policyForm.enabled,
                        }),
                    });
                    policyModal.value = false;
                    toast.success('Policy created');
                    await loadPolicies();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deletePolicy(p) {
                askConfirm(`Delete policy "${p.name}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/tables/${p.table_id}/rls-policies/${p.id}`, { method: 'DELETE' });
                        toast.success('Policy deleted');
                        await loadPolicies();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadTables();
            loadPolicies();

            return {
                tables, policies, loading, search, tableFilter, total, limit, offset, filteredPolicies,
                operations, policyModal, policyForm, confirmState,
                commandBadgeClass, openPolicyModal, createPolicy, deletePolicy, prevPage, nextPage,
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
        'title'     => 'Database — Loxodontu',
        'pageTitle' => 'Database',
    ],
];

include t('layouts/dashboard');
