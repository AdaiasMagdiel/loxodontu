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
            <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">Tables</p>
            <button class="btn-accent" @click="openTableModal()">+ New Table</button>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <template v-else>
            <div v-if="tables.length === 0" class="rounded-lg p-8 text-center text-sm" style="background:var(--bg-surface); border:1px solid var(--border); color:var(--text-muted);">
                No tables yet.
            </div>

            <div v-for="table in tables" :key="table.id" class="rounded-lg mb-3 overflow-hidden" style="background:var(--bg-surface); border:1px solid var(--border);">
                <div class="flex items-center justify-between px-5 py-3 cursor-pointer" @click="toggleTable(table.id)">
                    <div class="flex items-center gap-3">
                        <span class="font-head font-medium text-sm" style="color:var(--text-main);">{{ table.name }}</span>
                        <span class="text-xs font-mono" style="color:var(--text-muted);">{{ table.columns.length + 1 }} columns incl. id</span>
                    </div>
                    <div class="flex items-center gap-2" @click.stop>
                        <button class="btn-ghost" @click="startRename(table)">Rename</button>
                        <button class="btn-ghost-danger" @click="deleteTable(table)">Delete</button>
                    </div>
                </div>

                <div v-if="ui(table.id).renaming" class="px-5 pb-3 flex gap-2" @click.stop>
                    <input v-model="ui(table.id).renameValue" class="input" placeholder="New table name" />
                    <button class="btn-accent" @click="submitRename(table)">Save</button>
                    <button class="btn-ghost" @click="ui(table.id).renaming = false">Cancel</button>
                </div>

                <div v-if="ui(table.id).expanded" style="border-top:1px solid var(--border);">
                    <!-- Columns -->
                    <div class="px-5 py-4">
                        <p class="font-head font-medium text-xs uppercase tracking-widest mb-2" style="color:var(--text-muted);">Columns</p>
                        <div class="rounded-md overflow-hidden mb-3" style="border:1px solid var(--border);">
                            <div class="flex items-center px-3 py-2 text-xs font-mono" style="border-bottom:1px solid var(--border); color:var(--text-muted);">
                                <span class="w-28">id</span><span class="flex-1">BIGINT UNSIGNED</span><span class="w-48">primary key, auto increment</span>
                            </div>
                            <div v-for="col in table.columns" :key="col.id" class="px-3 py-2" style="border-bottom:1px solid var(--border);">
                                <div v-if="ui(table.id).editingColumn !== col.id" class="flex items-center justify-between">
                                    <div class="flex items-center gap-3 font-mono text-xs" style="color:var(--text-main);">
                                        <span class="w-28 truncate">{{ col.name }}</span>
                                        <span style="color:var(--text-muted);">{{ col.type }}</span>
                                        <span v-if="col.nullable" style="color:var(--text-muted);">nullable</span>
                                        <span v-if="col.default_value !== null" style="color:var(--text-muted);">default: {{ col.default_value }}</span>
                                        <span v-if="col.reference_table" style="color:var(--text-muted);">fk: {{ col.reference_table }}.{{ col.reference_column }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button class="btn-ghost" @click="startEditColumn(table, col)">Edit</button>
                                        <button class="btn-ghost-danger" @click="deleteColumn(table, col)">Delete</button>
                                    </div>
                                </div>
                                <div v-else class="flex items-center gap-2 flex-wrap">
                                    <input v-model="ui(table.id).editForm.name" class="input flex-1 min-w-[100px]" />
                                    <select v-model="ui(table.id).editForm.type" class="input w-32">
                                        <option v-for="t in columnTypes" :key="t" :value="t">{{ t }}</option>
                                    </select>
                                    <label class="flex items-center gap-1 text-xs" style="color:var(--text-muted);">
                                        <input type="checkbox" v-model="ui(table.id).editForm.nullable" /> nullable
                                    </label>
                                    <input v-model="ui(table.id).editForm.default_value" placeholder="default" class="input w-28" />
                                    <select v-model="ui(table.id).editForm.references_table" class="input w-36">
                                        <option value="">no fk</option>
                                        <option v-for="t in tables" :key="t.id" :value="t.name">{{ t.name }}</option>
                                    </select>
                                    <input v-model="ui(table.id).editForm.references_column" placeholder="ref column" class="input w-28" />
                                    <button class="btn-accent" @click="submitEditColumn(table, col)">Save</button>
                                    <button class="btn-ghost" @click="ui(table.id).editingColumn = null">Cancel</button>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 flex-wrap">
                            <input v-model="ui(table.id).newColumn.name" placeholder="column name" class="input flex-1 min-w-[100px]" />
                            <select v-model="ui(table.id).newColumn.type" class="input w-32">
                                <option v-for="t in columnTypes" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <label class="flex items-center gap-1 text-xs" style="color:var(--text-muted);">
                                <input type="checkbox" v-model="ui(table.id).newColumn.nullable" /> nullable
                            </label>
                            <input v-model="ui(table.id).newColumn.default_value" placeholder="default" class="input w-28" />
                            <select v-model="ui(table.id).newColumn.references_table" class="input w-36">
                                <option value="">no fk</option>
                                <option v-for="t in tables" :key="t.id" :value="t.name">{{ t.name }}</option>
                            </select>
                            <input v-model="ui(table.id).newColumn.references_column" placeholder="ref column" class="input w-28" />
                            <button class="btn-accent" @click="addColumn(table)">+ Add</button>
                        </div>
                    </div>

                    <!-- RLS Policies -->
                    <div class="px-5 py-4" style="border-top:1px solid var(--border);">
                        <p class="font-head font-medium text-xs uppercase tracking-widest mb-2" style="color:var(--text-muted);">RLS Policies</p>

                        <div v-if="!ui(table.id).policiesLoaded" class="text-xs" style="color:var(--text-muted);">Loading…</div>
                        <div v-else>
                            <div v-if="ui(table.id).policies.length === 0" class="text-xs mb-3" style="color:var(--text-muted);">No policies — table is unrestricted.</div>
                            <div v-for="pol in ui(table.id).policies" :key="pol.id" class="flex items-center justify-between px-3 py-2 mb-2 rounded-md" style="border:1px solid var(--border);">
                                <div class="text-xs font-mono" style="color:var(--text-main);">
                                    <span class="font-head font-medium uppercase">{{ pol.operation }}</span>
                                    <span v-if="pol.role" style="color:var(--text-muted);"> · role: {{ pol.role }}</span>
                                    <span v-if="pol.name" style="color:var(--text-muted);"> · {{ pol.name }}</span>
                                    <span v-if="Object.keys(pol.conditions).length" style="color:var(--text-muted);"> · {{ formatConditions(pol.conditions) }}</span>
                                    <span v-else style="color:var(--text-muted);"> · unrestricted</span>
                                </div>
                                <button class="btn-ghost-danger" @click="deletePolicy(table, pol)">Delete</button>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap mt-2">
                                <input v-model="ui(table.id).newPolicy.name" placeholder="policy name" class="input w-32" />
                                <select v-model="ui(table.id).newPolicy.operation" class="input w-28">
                                    <option v-for="op in rlsOperations" :key="op" :value="op">{{ op }}</option>
                                </select>
                                <input v-model="ui(table.id).newPolicy.role" placeholder="role (optional)" class="input w-32" />
                            </div>
                            <div v-for="(row, idx) in ui(table.id).newPolicy.conditions" :key="idx" class="flex items-center gap-2 flex-wrap mt-2">
                                <select v-model="row.column" class="input w-28">
                                    <option v-for="c in tableColumnNames(table)" :key="c" :value="c">{{ c }}</option>
                                </select>
                                <select v-model="row.op" class="input w-32">
                                    <option value="eq">= (eq)</option>
                                    <option value="ne">≠ (ne)</option>
                                    <option value="gt">&gt; (gt)</option>
                                    <option value="gte">≥ (gte)</option>
                                    <option value="lt">&lt; (lt)</option>
                                    <option value="lte">≤ (lte)</option>
                                    <option value="is_null">IS NULL</option>
                                    <option value="is_not_null">IS NOT NULL</option>
                                </select>
                                <template v-if="row.op !== 'is_null' && row.op !== 'is_not_null'">
                                    <select v-model="row.valueType" class="input w-28">
                                        <option value="placeholder">placeholder</option>
                                        <option value="string">string</option>
                                        <option value="number">number</option>
                                        <option value="boolean">boolean</option>
                                        <option value="null">null</option>
                                    </select>
                                    <select v-if="row.valueType === 'placeholder'" v-model="row.value" class="input w-36">
                                        <option v-for="ph in authPlaceholders" :key="ph" :value="ph">{{ ph }}</option>
                                    </select>
                                    <select v-else-if="row.valueType === 'boolean'" v-model="row.value" class="input w-24">
                                        <option value="true">true</option>
                                        <option value="false">false</option>
                                    </select>
                                    <input v-else-if="row.valueType !== 'null'" v-model="row.value" class="input w-36" placeholder="value" />
                                </template>
                                <button class="btn-ghost-danger" @click="ui(table.id).newPolicy.conditions.splice(idx, 1)">&times;</button>
                            </div>
                            <div class="flex items-center gap-2 mt-2">
                                <button class="btn-ghost" @click="ui(table.id).newPolicy.conditions.push({ column: tableColumnNames(table)[0], op: 'eq', valueType: 'placeholder', value: '$auth.id' })">+ Condition</button>
                                <button class="btn-accent" @click="addPolicy(table)">+ Add Policy</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- NEW TABLE MODAL -->
        <div v-if="tableModal" class="modal-backdrop" @click.self="tableModal = false">
            <div class="modal-card">
                <p class="modal-title">New Table</p>
                <label class="field-label">Name<input v-model="tableForm.name" class="input mt-1" placeholder="e.g. posts" /></label>
                <div class="rounded-md px-3 py-2 mt-3 text-xs" style="background:var(--accent-dim); border:1px solid var(--border); color:var(--text-muted);">
                    Every table automatically gets an <span class="font-mono" style="color:var(--text-main);">id</span> column as BIGINT UNSIGNED primary key with auto increment.
                </div>

                <p class="field-label mt-4 mb-2">Columns</p>
                <div v-for="(col, idx) in tableForm.columns" :key="idx" class="flex items-center gap-2 flex-wrap mb-2">
                    <input v-model="col.name" placeholder="name" class="input flex-1 min-w-[90px]" />
                    <select v-model="col.type" class="input w-32">
                        <option v-for="t in columnTypes" :key="t" :value="t">{{ t }}</option>
                    </select>
                    <label class="flex items-center gap-1 text-xs" style="color:var(--text-muted);">
                        <input type="checkbox" v-model="col.nullable" /> null
                    </label>
                    <input v-model="col.default_value" placeholder="default" class="input w-28" />
                    <select v-model="col.references_table" class="input w-36">
                        <option value="">no fk</option>
                        <option v-for="t in tables" :key="t.id" :value="t.name">{{ t.name }}</option>
                    </select>
                    <input v-model="col.references_column" placeholder="ref column" class="input w-28" />
                    <button class="btn-ghost-danger" @click="tableForm.columns.splice(idx, 1)">&times;</button>
                </div>
                <button class="btn-ghost" @click="tableForm.columns.push(blankColumn())">+ Column</button>

                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn-ghost" @click="tableModal = false">Cancel</button>
                    <button class="btn-accent" @click="createTable">Create</button>
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

            const columnTypes = ['text', 'longtext', 'integer', 'bigint', 'decimal', 'float', 'boolean', 'date', 'time', 'timestamp', 'json', 'uuid'];
            const rlsOperations = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALL'];
            const authPlaceholders = ['$auth.id', '$auth.email', '$auth.role'];

            const project = Vue.ref(null);
            const tables = Vue.ref([]);
            const loading = Vue.ref(true);

            const tableModal = Vue.ref(false);
            function blankColumn() {
                return { name: '', type: 'text', nullable: false, default_value: '', references_table: '', references_column: '' };
            }
            const tableForm = Vue.reactive({ name: '', columns: [blankColumn()] });

            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });

            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            const tableUiMap = Vue.reactive({});
            function ui(tableId) {
                if (!tableUiMap[tableId]) {
                    tableUiMap[tableId] = {
                        expanded: false,
                        renaming: false,
                        renameValue: '',
                        editingColumn: null,
                        editForm: {},
                        newColumn: blankColumn(),
                        policies: [],
                        policiesLoaded: false,
                        newPolicy: { name: '', operation: 'SELECT', role: '', conditions: [] },
                    };
                }
                return tableUiMap[tableId];
            }

            function tableColumnNames(table) {
                return table.columns.map(c => c.name).concat(['id']);
            }

            async function loadProject() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}`);
                    project.value = body;
                    tables.value = body.tables;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function openTableModal() {
                tableForm.name = '';
                tableForm.columns = [blankColumn()];
                tableModal.value = true;
            }

            function columnPayload(col) {
                return {
                    name: col.name,
                    type: col.type,
                    nullable: col.nullable,
                    default_value: col.default_value === '' ? null : col.default_value,
                    references_table: col.references_table || null,
                    references_column: col.references_table ? (col.references_column || 'id') : null,
                };
            }

            async function createTable() {
                if (!tableForm.name.trim()) { toast.error('Name is required'); return; }
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/tables`, {
                        method: 'POST',
                        body: JSON.stringify({
                            name: tableForm.name,
                            columns: tableForm.columns.filter(c => c.name.trim()).map(columnPayload),
                        }),
                    });
                    tableModal.value = false;
                    toast.success('Table created');
                    await loadProject();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function toggleTable(id) {
                const state = ui(id);
                state.expanded = !state.expanded;
                if (state.expanded && !state.policiesLoaded) loadPolicies(id);
            }

            function startRename(table) {
                const state = ui(table.id);
                state.renaming = true;
                state.renameValue = table.name;
            }

            async function submitRename(table) {
                const state = ui(table.id);
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/tables/${table.id}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ name: state.renameValue }),
                    });
                    state.renaming = false;
                    toast.success('Table renamed');
                    await loadProject();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteTable(table) {
                askConfirm(`Delete table "${table.name}" and all its data?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/tables/${table.id}`, { method: 'DELETE' });
                        toast.success('Table deleted');
                        await loadProject();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            async function addColumn(table) {
                const state = ui(table.id);
                const col = state.newColumn;
                if (!col.name.trim()) { toast.error('Column name is required'); return; }
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/tables/${table.id}/columns`, {
                        method: 'POST',
                        body: JSON.stringify({
                            name: col.name, type: col.type, nullable: col.nullable,
                            default_value: col.default_value === '' ? null : col.default_value,
                            references_table: col.references_table || null,
                            references_column: col.references_table ? (col.references_column || 'id') : null,
                        }),
                    });
                    state.newColumn = blankColumn();
                    toast.success('Column added');
                    await loadProject();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function startEditColumn(table, col) {
                const state = ui(table.id);
                state.editingColumn = col.id;
                state.editForm = {
                    name: col.name,
                    type: col.type,
                    nullable: col.nullable,
                    default_value: col.default_value ?? '',
                    references_table: col.reference_table || '',
                    references_column: col.reference_column || '',
                };
            }

            async function submitEditColumn(table, col) {
                const state = ui(table.id);
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/tables/${table.id}/columns/${col.id}`, {
                        method: 'PATCH',
                        body: JSON.stringify({
                            ...state.editForm,
                            default_value: state.editForm.default_value === '' ? null : state.editForm.default_value,
                            references_table: state.editForm.references_table || null,
                            references_column: state.editForm.references_table ? (state.editForm.references_column || 'id') : null,
                        }),
                    });
                    state.editingColumn = null;
                    toast.success('Column updated');
                    await loadProject();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteColumn(table, col) {
                askConfirm(`Delete column "${col.name}"? Its data will be lost.`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/tables/${table.id}/columns/${col.id}?confirm=true`, { method: 'DELETE' });
                        toast.success('Column deleted');
                        await loadProject();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            async function loadPolicies(tableId) {
                const state = ui(tableId);
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/tables/${tableId}/rls-policies`);
                    state.policies = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    state.policiesLoaded = true;
                }
            }

            function coerceValue(row) {
                switch (row.valueType) {
                    case 'null':    return null;
                    case 'number':  return Number(row.value);
                    case 'boolean': return row.value === 'true';
                    default:        return row.value;
                }
            }

            function buildCondition(row) {
                const noValueOps = ['is_null', 'is_not_null'];
                if (row.op === 'eq') return coerceValue(row); // backward-compat scalar
                if (noValueOps.includes(row.op)) return { op: row.op };
                return { op: row.op, value: coerceValue(row) };
            }

            function formatConditions(conditions) {
                const opLabels = { eq: '=', ne: '≠', gt: '>', gte: '≥', lt: '<', lte: '≤', is_null: 'IS NULL', is_not_null: 'IS NOT NULL' };
                return Object.entries(conditions).map(([col, val]) => {
                    if (val !== null && typeof val === 'object' && val.op) {
                        const label = opLabels[val.op] || val.op;
                        return val.value !== undefined ? `${col} ${label} ${val.value}` : `${col} ${label}`;
                    }
                    return `${col} = ${val}`;
                }).join(', ');
            }

            async function addPolicy(table) {
                const state = ui(table.id);
                const form = state.newPolicy;
                if (!form.name.trim()) { toast.error('Policy name is required'); return; }

                const conditions = {};
                form.conditions.forEach(row => { conditions[row.column] = buildCondition(row); });

                try {
                    await apiFetch(`/projects/${PROJECT_ID}/tables/${table.id}/rls-policies`, {
                        method: 'POST',
                        body: JSON.stringify({
                            name: form.name,
                            operation: form.operation,
                            role: form.role.trim() || null,
                            conditions,
                        }),
                    });
                    state.newPolicy = { name: '', operation: 'SELECT', role: '', conditions: [] };
                    toast.success('Policy created');
                    await loadPolicies(table.id);
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deletePolicy(table, policy) {
                askConfirm(`Delete policy "${policy.name}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/tables/${table.id}/rls-policies/${policy.id}`, { method: 'DELETE' });
                        toast.success('Policy deleted');
                        await loadPolicies(table.id);
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadProject();

            return {
                project, tables, loading, columnTypes, rlsOperations, authPlaceholders,
                tableModal, tableForm, confirmState, ui, tableColumnNames,
                blankColumn,
                openTableModal, createTable, toggleTable, startRename, submitRename, deleteTable,
                addColumn, startEditColumn, submitEditColumn, deleteColumn,
                addPolicy, deletePolicy, formatConditions,
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
        'title'     => 'Tables — Loxodontu',
        'pageTitle' => 'Tables',
    ],
];

include t('layouts/dashboard');
