<?php
/** @var int $projectId */
/** @var int $bucketId */
ob_start();
?>
<template id="tpl-page">
    <nav class="breadcrumb">
        <a :href="`/dashboard/projects/${PROJECT_ID}`">Overview</a>
        <span class="breadcrumb-sep">/</span>
        <a :href="`/dashboard/projects/${PROJECT_ID}/storage`">Storage</a>
        <span class="breadcrumb-sep">/</span>
        <span class="breadcrumb-current">{{ bucket ? bucket.name : '…' }}</span>
    </nav>

    <div class="mb-6 flex items-center gap-3">
        <h1 class="page-title">{{ bucket ? bucket.name : '…' }}</h1>
        <span v-if="bucket" class="badge" :class="bucket.public ? 'badge-accent' : ''">{{ bucket.public ? 'Public' : 'Private' }}</span>
    </div>

    <div class="subnav">
        <button class="subnav-item" :class="{ active: tab === 'files' }" @click="switchTab('files')">Files</button>
        <button class="subnav-item" :class="{ active: tab === 'policies' }" @click="switchTab('policies')">Policies</button>
        <button class="subnav-item" :class="{ active: tab === 'settings' }" @click="switchTab('settings')">Settings</button>
    </div>

    <!-- FILES TAB -->
    <div v-show="tab === 'files'">
        <div class="listbar">
            <input type="file" :ref="el => fileInput = el" class="text-xs" style="color:var(--text-muted);" />
            <input v-model="uploadPath" placeholder="path (optional, defaults to file name)" class="input flex-1 min-w-[160px]" />
            <button class="btn-accent" :disabled="uploading" @click="uploadObject">{{ uploading ? 'Uploading…' : '+ Upload' }}</button>
        </div>

        <div v-if="!objectsLoaded" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <div v-else-if="objects.length === 0" class="empty-state">
            <p class="empty-state-title">No files yet</p>
            <p class="empty-state-body">Upload a file to get started.</p>
        </div>
        <div v-else class="datalist">
            <div class="datalist-head"><div class="datalist-cell">Path</div><div class="datalist-cell" style="flex:0 0 6rem;">Size</div><div class="datalist-cell" style="flex:0 0 10rem;">Type</div><div style="flex:0 0 10rem;"></div></div>
            <div v-for="obj in objects" :key="obj.id" class="datalist-row" style="cursor:pointer;" @click="openFileDrawer(obj)">
                <div class="datalist-cell font-mono">{{ obj.path }}</div>
                <div class="datalist-cell" style="flex:0 0 6rem;">{{ formatSize(obj.size) }}</div>
                <div class="datalist-cell" style="flex:0 0 10rem;">{{ obj.mime_type || '—' }}</div>
                <div style="flex:0 0 10rem;" class="flex justify-end gap-2" @click.stop>
                    <button class="btn-ghost" @click="downloadObject(obj)">Download</button>
                    <button class="btn-ghost-danger" @click="deleteObject(obj)">Delete</button>
                </div>
            </div>
        </div>
        <div class="pagination">
            <span class="pagination-info">{{ objects.length === 0 ? 0 : objectsOffset + 1 }}–{{ objectsOffset + objects.length }} of {{ objectsTotal }}</span>
            <div class="flex gap-2">
                <button class="btn-ghost" :disabled="objectsOffset === 0" @click="objectsOffset = Math.max(0, objectsOffset - objectsLimit); loadObjects();">Previous</button>
                <button class="btn-ghost" :disabled="objectsOffset + objectsLimit >= objectsTotal" @click="objectsOffset += objectsLimit; loadObjects();">Next</button>
            </div>
        </div>
    </div>

    <!-- POLICIES TAB -->
    <div v-show="tab === 'policies'">
        <div v-if="!policiesLoaded" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <template v-else>
            <div v-if="policies.length === 0" class="empty-state">
                <p class="empty-state-title">No policies yet</p>
                <p class="empty-state-body">Every <span class="font-mono">storage:*</span>-scoped API key can do anything through the passthrough.</p>
            </div>
            <div v-else class="datalist mb-4">
                <div v-for="pol in policies" :key="pol.id" class="datalist-row">
                    <div class="datalist-cell">
                        <span class="badge badge-accent">{{ pol.operation }}</span>
                        <span class="ml-2 font-medium" style="color:var(--text-main);">{{ pol.name }}</span>
                        <span class="ml-2 font-mono text-xs" style="color:var(--text-muted);">{{ pol.expression }}</span>
                    </div>
                    <button class="btn-ghost-danger" @click="deletePolicy(pol)">Delete</button>
                </div>
            </div>

            <div class="card">
                <p class="field-label mb-2">New Policy</p>
                <div class="flex items-center gap-2 flex-wrap mb-2">
                    <input v-model="newPolicy.name" placeholder="policy name" class="input w-40" />
                    <select v-model="newPolicy.operation" class="input w-32">
                        <option v-for="op in rlsOperations" :key="op" :value="op">{{ op }}</option>
                    </select>
                </div>
                <textarea v-model="newPolicy.expression" rows="2" class="input font-mono" placeholder="owner_id = $auth.id"></textarea>
                <p class="text-xs mt-1" style="color:var(--text-muted);">
                    Raw SQL boolean expression over an object's columns. Placeholders:
                    <code v-for="ph in authPlaceholders" :key="ph" class="font-mono mr-1" style="color:var(--accent);">{{ ph }}</code>
                </p>
                <div class="flex justify-end mt-2">
                    <button class="btn-accent" @click="addPolicy">+ Add Policy</button>
                </div>
            </div>
        </template>
    </div>

    <!-- SETTINGS TAB -->
    <div v-show="tab === 'settings'">
        <div class="card mb-6">
            <p class="field-label mb-3">Visibility</p>
            <label class="flex items-center gap-2 text-sm" style="color:var(--text-main);">
                <input type="checkbox" :checked="bucket && bucket.public" @change="togglePublic" /> Public (files served with no authentication)
            </label>
        </div>
        <div class="card" style="border-color:var(--danger);">
            <p class="field-label mb-3" style="color:var(--danger);">Danger Zone</p>
            <button class="btn-ghost-danger" @click="deleteBucket">Delete bucket</button>
        </div>
    </div>

    <!-- FILE DRAWER -->
    <div v-if="drawer.show" class="drawer-backdrop" @click.self="drawer.show = false">
        <div class="drawer" data-dismiss-on-esc>
            <p class="modal-title">{{ drawer.object ? drawer.object.path : '' }}</p>
            <div v-if="drawer.object" class="text-sm" style="color:var(--text-muted);">
                <p class="mb-2">Size: {{ formatSize(drawer.object.size) }}</p>
                <p class="mb-2">Type: {{ drawer.object.mime_type || '—' }}</p>
            </div>
            <div class="flex gap-2 mt-auto pt-4">
                <button class="btn-ghost" @click="downloadObject(drawer.object)">Download</button>
                <button class="btn-ghost-danger" @click="deleteObject(drawer.object); drawer.show = false;">Delete</button>
                <button class="btn-ghost" @click="drawer.show = false">Close</button>
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
            const BUCKET_ID = <?= json_encode((string) $bucketId) ?>;
            const rlsOperations = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALL'];
            const authPlaceholders = ['$auth.id', '$auth.email', '$auth.role'];

            const bucket = Vue.ref(null);
            const tab = Vue.ref('files');

            const objects = Vue.ref([]);
            const objectsLoaded = Vue.ref(false);
            const objectsTotal = Vue.ref(0);
            const objectsLimit = 25;
            const objectsOffset = Vue.ref(0);
            const fileInput = Vue.ref(null);
            const uploadPath = Vue.ref('');
            const uploading = Vue.ref(false);

            const policies = Vue.ref([]);
            const policiesLoaded = Vue.ref(false);
            const newPolicy = Vue.reactive({ name: '', operation: 'SELECT', expression: '' });

            const drawer = Vue.reactive({ show: false, object: null });
            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });

            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }

            function switchTab(name) {
                tab.value = name;
                if (name === 'policies' && !policiesLoaded.value) loadPolicies();
            }

            async function loadBucket() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/storage/buckets`);
                    bucket.value = body.find((b) => String(b.id) === String(BUCKET_ID)) || null;
                    if (!bucket.value) toast.error('Bucket not found');
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function loadObjects() {
                objectsLoaded.value = false;
                try {
                    const { body, headers } = await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}/objects?limit=${objectsLimit}&offset=${objectsOffset.value}`);
                    objects.value = body;
                    objectsTotal.value = parseInt(headers.get('X-Total-Count') || '0', 10);
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    objectsLoaded.value = true;
                }
            }

            async function uploadObject() {
                const file = fileInput.value && fileInput.value.files && fileInput.value.files[0];
                if (!file) { toast.error('Choose a file first'); return; }

                const form = new FormData();
                form.append('file', file);
                if (uploadPath.value.trim()) form.append('path', uploadPath.value.trim());

                uploading.value = true;
                try {
                    const headers = { Authorization: 'Bearer ' + store.auth.token };
                    const res = await fetch(`/api/v1/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}/objects`, { method: 'POST', headers, body: form });
                    const responseBody = await res.json().catch(() => null);
                    if (!res.ok) throw new Error((responseBody && responseBody.error) || `Upload failed (${res.status})`);

                    uploadPath.value = '';
                    if (fileInput.value) fileInput.value.value = '';
                    toast.success('File uploaded');
                    await loadObjects();
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    uploading.value = false;
                }
            }

            async function downloadObject(obj) {
                try {
                    const headers = { Authorization: 'Bearer ' + store.auth.token };
                    const res = await fetch(`/api/v1/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}/objects/${obj.id}/download`, { headers });
                    if (!res.ok) throw new Error(`Download failed (${res.status})`);
                    const blob = await res.blob();
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = obj.path.split('/').pop();
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    setTimeout(() => URL.revokeObjectURL(url), 10000);
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteObject(obj) {
                askConfirm(`Delete "${obj.path}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}/objects/${obj.id}`, { method: 'DELETE' });
                        toast.success('File deleted');
                        await loadObjects();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            function openFileDrawer(obj) {
                drawer.object = obj;
                drawer.show = true;
            }

            async function loadPolicies() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}/policies`);
                    policies.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    policiesLoaded.value = true;
                }
            }

            async function addPolicy() {
                if (!newPolicy.name.trim()) { toast.error('Policy name is required'); return; }
                if (!newPolicy.expression.trim()) { toast.error('Expression is required'); return; }
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}/policies`, {
                        method: 'POST',
                        body: JSON.stringify({ name: newPolicy.name, operation: newPolicy.operation, expression: newPolicy.expression }),
                    });
                    newPolicy.name = ''; newPolicy.expression = ''; newPolicy.operation = 'SELECT';
                    toast.success('Policy created');
                    await loadPolicies();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deletePolicy(pol) {
                askConfirm(`Delete policy "${pol.name}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}/policies/${pol.id}`, { method: 'DELETE' });
                        toast.success('Policy deleted');
                        await loadPolicies();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            async function togglePublic() {
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ public: !(bucket.value && bucket.value.public) }),
                    });
                    toast.success('Bucket updated');
                    await loadBucket();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteBucket() {
                askConfirm(`Delete bucket "${bucket.value ? bucket.value.name : ''}" and all its files? This cannot be undone.`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${BUCKET_ID}`, { method: 'DELETE' });
                        toast.success('Bucket deleted');
                        location.href = `/dashboard/projects/${PROJECT_ID}/storage`;
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadBucket();
            loadObjects();

            return {
                PROJECT_ID, bucket, tab, switchTab,
                objects, objectsLoaded, objectsTotal, objectsLimit, objectsOffset, fileInput, uploadPath, uploading,
                policies, policiesLoaded, newPolicy, rlsOperations, authPlaceholders,
                drawer, confirmState, formatSize,
                loadObjects, uploadObject, downloadObject, deleteObject, openFileDrawer,
                addPolicy, deletePolicy, togglePublic, deleteBucket,
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
        'title'     => 'Bucket — Loxodontu',
        'pageTitle' => 'Storage',
    ],
];

include t('layouts/dashboard');
