<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <main>
        <div class="mb-4">
            <h1 class="page-title">Table Editor</h1>
            <p class="page-subtitle">Browse and edit rows in your tables &mdash; click any cell to edit it.</p>
        </div>

        <div v-if="loadingTables" class="text-sm" style="color:var(--text-muted);">Loading&hellip;</div>

        <div v-else-if="tables.length === 0" class="empty-state">
            <p class="empty-state-title">No tables yet</p>
            <p class="empty-state-body">Create your first table to start storing data.</p>
            <button class="btn-accent" @click="openNewTableModal">+ New table</button>
        </div>

        <div v-else class="te-layout">
            <div class="te-tables">
                <div class="te-tables-head">
                    <span class="font-head text-xs uppercase tracking-wide" style="color:var(--text-muted);">Tables</span>
                    <button class="btn-ghost btn-icon" title="New table" @click="openNewTableModal">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg>
                    </button>
                </div>
                <div class="te-tables-list">
                    <div v-for="tbl in tables" :key="tbl.id" class="te-table-item" :class="{ active: tbl.id === activeTableId }" @click="selectTable(tbl.id)">
                        <span class="font-mono">{{ tbl.name }}</span>
                        <span class="count">{{ rowCounts[tbl.id] !== undefined ? rowCounts[tbl.id] : '' }}</span>
                    </div>
                </div>
            </div>

            <div class="te-main">
                <div class="te-toolbar">
                    <span class="font-mono font-medium text-sm" style="color:var(--text-main);">{{ activeTable ? activeTable.name : '' }}</span>
                    <span v-if="activeTable" class="badge" :class="policyCount > 0 ? 'badge-success' : 'badge-warning'">
                        {{ policyCount }} {{ policyCount === 1 ? 'policy' : 'policies' }}
                    </span>
                    <span v-if="activeTable" class="text-xs" style="color:var(--text-muted);">{{ totalRows }} rows &middot; {{ activeTable.columns.length + 1 }} columns</span>
                    <div class="flex-1"></div>
                    <input v-model="search" @input="onSearchInput" class="input" style="max-width:16rem;" placeholder="Search rows&hellip;" />
                    <button class="btn-ghost" :disabled="!activeTable" @click="openAddColumnModal">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg> Column
                    </button>
                    <button class="btn-accent" :disabled="!activeTable || inserting" @click="insertRow">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19" /><line x1="5" y1="12" x2="19" y2="12" /></svg> Insert row
                    </button>
                </div>

                <div class="te-grid-wrap">
                    <div v-if="rowsLoading" class="p-4">
                        <div class="skeleton mb-2" style="height:1.5rem;"></div>
                        <div class="skeleton mb-2" style="height:1.5rem;"></div>
                        <div class="skeleton" style="height:1.5rem;"></div>
                    </div>
                    <table v-else-if="activeTable" class="te-grid">
                        <thead>
                            <tr>
                                <th class="te-gutter"></th>
                                <th>
                                    <div class="te-th-inner" style="cursor:default;">
                                        <span class="te-col-name">id</span>
                                        <span class="te-col-type">bigint</span>
                                    </div>
                                </th>
                                <th v-for="col in activeTable.columns" :key="col.id">
                                    <div class="te-th-inner">
                                        <span class="te-col-del" title="Delete column" @click.stop="deleteColumn(col)">
                                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" /></svg>
                                        </span>
                                        <span class="flex items-center gap-2" style="cursor:pointer;" @click="toggleSort(col.name)">
                                            <span class="te-col-name">{{ col.name }}</span>
                                            <span class="te-col-type">{{ col.type }}</span>
                                            <span v-if="sortCol === col.name" class="te-sort-caret">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
                                        </span>
                                    </div>
                                </th>
                                <th class="te-add-col-th" title="Add column" @click="openAddColumnModal">+</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="rows.length === 0" class="te-empty-row">
                                <td :colspan="activeTable.columns.length + 3">
                                    No rows{{ search ? ' match your search' : '' }}.
                                    <template v-if="!search"><br>Click <strong>Insert row</strong> to add the first one.</template>
                                </td>
                            </tr>
                            <tr v-for="(row, idx) in rows" :key="row.id" class="te-row">
                                <td class="te-gutter">
                                    <span class="te-row-num">{{ offset + idx + 1 }}</span>
                                    <span class="te-row-del" @click="deleteRow(row)">
                                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /></svg>
                                    </span>
                                </td>
                                <td><span class="te-cell" style="cursor:default;">{{ row.id }}</span></td>
                                <td v-for="col in activeTable.columns" :key="col.id">
                                    <input v-if="isEditing(row.id, col.name)" class="te-cell-input" :value="editing.value" autofocus
                                        @input="editing.value = $event.target.value"
                                        @keydown.enter="commitEdit(row)"
                                        @keydown.escape="cancelEdit"
                                        @blur="commitEdit(row)" />
                                    <span v-else class="te-cell" :class="{ 'te-null': row[col.name] === null || row[col.name] === '' }" @click="startEdit(row, col.name)">
                                        {{ row[col.name] === null || row[col.name] === '' ? 'null' : row[col.name] }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="activeTable && totalRows > 0" class="pagination" style="padding:0.6rem 0.85rem;border-top:1px solid var(--border);margin-top:0;">
                    <span class="pagination-info">{{ offset + 1 }}&ndash;{{ Math.min(offset + limit, totalRows) }} of {{ totalRows }}</span>
                    <div class="flex gap-2">
                        <button class="btn-ghost" :disabled="offset === 0" @click="prevPage">Previous</button>
                        <button class="btn-ghost" :disabled="offset + limit >= totalRows" @click="nextPage">Next</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- NEW TABLE MODAL -->
        <div v-if="newTableModal" class="modal-backdrop" @click.self="newTableModal = false">
            <div class="modal-card">
                <p class="modal-title">New table</p>
                <label class="field-label mb-1 block">Table name</label>
                <input v-model="newTableName" class="input mb-4" placeholder="e.g. subscriptions" @keydown.enter="submitNewTable" />
                <p class="text-xs mb-4" style="color:var(--text-muted);">Starts with a single <span class="font-mono">id (bigint, primary key)</span> column &mdash; add more after creating it.</p>
                <div class="flex justify-end gap-2">
                    <button class="btn-ghost" @click="newTableModal = false">Cancel</button>
                    <button class="btn-accent" @click="submitNewTable">Create table</button>
                </div>
            </div>
        </div>

        <!-- ADD COLUMN MODAL -->
        <div v-if="addColumnModal" class="modal-backdrop" @click.self="addColumnModal = false">
            <div class="modal-card">
                <p class="modal-title">Add column</p>
                <label class="field-label mb-1 block">Name</label>
                <input v-model="newColumn.name" class="input mb-3" placeholder="e.g. last_login" />
                <label class="field-label mb-1 block">Type</label>
                <select v-model="newColumn.type" class="input mb-3">
                    <option v-for="type in columnTypes" :key="type" :value="type">{{ type }}</option>
                </select>
                <label class="flex items-center gap-2 text-xs mb-3" style="color:var(--text-main);">
                    <input type="checkbox" v-model="newColumn.nullable" /> Nullable
                </label>
                <label class="field-label mb-1 block">Default value (optional)</label>
                <input v-model="newColumn.default_value" class="input mb-4" />
                <div class="flex justify-end gap-2">
                    <button class="btn-ghost" @click="addColumnModal = false">Cancel</button>
                    <button class="btn-accent" @click="submitAddColumn">Add column</button>
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
            const columnTypes = ['text', 'longtext', 'integer', 'bigint', 'decimal', 'float', 'boolean', 'date', 'time', 'timestamp', 'json', 'uuid'];

            const tables = Vue.ref([]);
            const loadingTables = Vue.ref(true);
            const activeTableId = Vue.ref(null);
            const activeTable = Vue.computed(() => tables.value.find((t) => t.id === activeTableId.value) || null);

            const rows = Vue.ref([]);
            const rowsLoading = Vue.ref(true);
            const rowCounts = Vue.reactive({});
            const totalRows = Vue.ref(0);
            const limit = 25;
            const offset = Vue.ref(0);
            const search = Vue.ref('');
            const sortCol = Vue.ref(null);
            const sortDir = Vue.ref('asc');
            const policyCount = Vue.ref(0);
            const inserting = Vue.ref(false);
            let searchTimer = null;

            const newTableModal = Vue.ref(false);
            const newTableName = Vue.ref('');
            const addColumnModal = Vue.ref(false);
            const newColumn = Vue.reactive({ name: '', type: 'text', nullable: false, default_value: '' });

            const editing = Vue.reactive({ rowId: null, col: null, value: '' });

            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });
            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            async function loadTables() {
                loadingTables.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/tables`);
                    tables.value = body;
                    if (body.length > 0 && activeTableId.value === null) {
                        selectTable(body[0].id);
                    }
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loadingTables.value = false;
                }
            }

            function selectTable(id) {
                activeTableId.value = id;
                offset.value = 0;
                search.value = '';
                sortCol.value = null;
                sortDir.value = 'asc';
                editing.rowId = null;
                loadRows();
                loadPolicyCount();
            }

            async function loadRows() {
                if (!activeTableId.value) return;
                rowsLoading.value = true;
                try {
                    const params = new URLSearchParams({ limit: String(limit), offset: String(offset.value) });
                    if (search.value.trim()) params.set('search', search.value.trim());
                    if (sortCol.value) { params.set('sort', sortCol.value); params.set('dir', sortDir.value); }

                    const { body, headers } = await apiFetch(`/projects/${PROJECT_ID}/tables/${activeTableId.value}/rows?${params}`);
                    rows.value = body;
                    totalRows.value = parseInt(headers.get('X-Total-Count') || '0', 10);
                    rowCounts[activeTableId.value] = totalRows.value;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    rowsLoading.value = false;
                }
            }

            async function loadPolicyCount() {
                if (!activeTableId.value) return;
                try {
                    const { headers } = await apiFetch(`/projects/${PROJECT_ID}/tables/${activeTableId.value}/rls-policies?limit=1`);
                    policyCount.value = parseInt(headers.get('X-Total-Count') || '0', 10);
                } catch (e) { /* non-critical */ }
            }

            function onSearchInput() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => { offset.value = 0; loadRows(); }, 350);
            }

            function toggleSort(colName) {
                if (sortCol.value === colName) {
                    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
                } else {
                    sortCol.value = colName;
                    sortDir.value = 'asc';
                }
                loadRows();
            }

            function prevPage() { offset.value = Math.max(0, offset.value - limit); loadRows(); }
            function nextPage() { offset.value += limit; loadRows(); }

            function openNewTableModal() { newTableName.value = ''; newTableModal.value = true; }

            async function submitNewTable() {
                const name = newTableName.value.trim();
                if (!name) { toast.error('Table name is required'); return; }
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/tables`, {
                        method: 'POST',
                        body: JSON.stringify({ name, columns: [] }),
                    });
                    newTableModal.value = false;
                    toast.success(`Table "${name}" created`);
                    await loadTables();
                    selectTable(body.id);
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function openAddColumnModal() {
                if (!activeTable.value) return;
                newColumn.name = '';
                newColumn.type = 'text';
                newColumn.nullable = false;
                newColumn.default_value = '';
                addColumnModal.value = true;
            }

            async function submitAddColumn() {
                const name = newColumn.name.trim();
                if (!name) { toast.error('Column name is required'); return; }
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/tables/${activeTableId.value}/columns`, {
                        method: 'POST',
                        body: JSON.stringify({
                            name,
                            type: newColumn.type,
                            nullable: newColumn.nullable,
                            default_value: newColumn.default_value.trim() || null,
                        }),
                    });
                    addColumnModal.value = false;
                    toast.success(`Column "${name}" added`);
                    await loadTables();
                    await loadRows();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteColumn(col) {
                askConfirm(`Delete column "${col.name}"? Data in this column will be lost.`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/tables/${activeTableId.value}/columns/${col.id}?confirm=true`, { method: 'DELETE' });
                        toast.success('Column deleted');
                        await loadTables();
                        await loadRows();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            async function insertRow() {
                if (!activeTableId.value) return;
                inserting.value = true;
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/tables/${activeTableId.value}/rows`, {
                        method: 'POST',
                        body: JSON.stringify({}),
                    });
                    toast.success('Row inserted');
                    offset.value = 0;
                    search.value = '';
                    await loadRows();
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    inserting.value = false;
                }
            }

            function deleteRow(row) {
                askConfirm('Delete this row? This cannot be undone.', async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/tables/${activeTableId.value}/rows/${row.id}`, { method: 'DELETE' });
                        toast.success('Row deleted');
                        await loadRows();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            function isEditing(rowId, col) { return editing.rowId === rowId && editing.col === col; }

            function startEdit(row, col) {
                editing.rowId = row.id;
                editing.col = col;
                editing.value = row[col] === null ? '' : String(row[col]);
            }

            function cancelEdit() { editing.rowId = null; editing.col = null; }

            async function commitEdit(row) {
                if (editing.rowId !== row.id) return;
                const col = editing.col;
                const value = editing.value;
                editing.rowId = null;
                editing.col = null;

                if (String(row[col] ?? '') === value) return;

                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/tables/${activeTableId.value}/rows/${row.id}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ [col]: value === '' ? null : value }),
                    });
                    Object.assign(row, body);
                } catch (e) {
                    toast.error(e.message);
                }
            }

            loadTables();

            return {
                tables, loadingTables, activeTableId, activeTable, selectTable,
                rows, rowsLoading, rowCounts, totalRows, offset, limit, search, sortCol, sortDir,
                policyCount, inserting, onSearchInput, toggleSort, prevPage, nextPage,
                newTableModal, newTableName, openNewTableModal, submitNewTable,
                addColumnModal, newColumn, columnTypes, openAddColumnModal, submitAddColumn, deleteColumn,
                insertRow, deleteRow, editing, isEditing, startEdit, cancelEdit, commitEdit,
                confirmState,
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
        'title'     => 'Table Editor — Loxodontu',
        'pageTitle' => 'Table Editor',
    ],
];

include t('layouts/dashboard');
