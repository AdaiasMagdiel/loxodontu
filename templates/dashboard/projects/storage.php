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
            <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">Storage Buckets</p>
            <button class="btn-accent" @click="openBucketModal()">+ New Bucket</button>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <template v-else>
            <div v-if="buckets.length === 0" class="rounded-lg p-8 text-center text-sm" style="background:var(--bg-surface); border:1px solid var(--border); color:var(--text-muted);">
                No buckets yet.
            </div>

            <div v-for="bucket in buckets" :key="bucket.id" class="rounded-lg mb-3 overflow-hidden" style="background:var(--bg-surface); border:1px solid var(--border);">
                <div class="flex items-center justify-between px-5 py-3 cursor-pointer" @click="toggleBucket(bucket)">
                    <div class="flex items-center gap-3">
                        <span class="font-head font-medium text-sm" style="color:var(--text-main);">{{ bucket.name }}</span>
                        <span class="text-xs font-head uppercase tracking-wide px-2 py-0.5 rounded" :style="bucket.public ? 'background:var(--accent-dim); color:var(--accent);' : 'border:1px solid var(--border); color:var(--text-muted);'">
                            {{ bucket.public ? 'Public' : 'Private' }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2" @click.stop>
                        <button class="btn-ghost" @click="togglePublic(bucket)">Make {{ bucket.public ? 'Private' : 'Public' }}</button>
                        <button class="btn-ghost-danger" @click="deleteBucket(bucket)">Delete</button>
                    </div>
                </div>

                <div v-if="ui(bucket.id).expanded" style="border-top:1px solid var(--border);">
                    <!-- Objects -->
                    <div class="px-5 py-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">Files</p>
                            <span class="text-xs" style="color:var(--text-muted);">{{ ui(bucket.id).objects.length }} file(s)</span>
                        </div>

                        <div v-if="!ui(bucket.id).objectsLoaded" class="text-xs" style="color:var(--text-muted);">Loading…</div>
                        <template v-else>
                            <div v-if="ui(bucket.id).objects.length === 0" class="text-xs mb-3" style="color:var(--text-muted);">No files yet.</div>
                            <div v-else class="rounded-md overflow-hidden mb-3" style="border:1px solid var(--border);">
                                <div v-for="obj in ui(bucket.id).objects" :key="obj.id" class="flex items-center justify-between px-3 py-2" style="border-bottom:1px solid var(--border);">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <button v-if="isImage(obj)" class="shrink-0" @click="previewObject(bucket, obj)" title="Preview">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--accent);">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </button>
                                        <span class="font-mono text-xs truncate" style="color:var(--text-main);">{{ obj.path }}</span>
                                        <span class="text-xs font-mono shrink-0" style="color:var(--text-muted);">{{ formatSize(obj.size) }}</span>
                                        <span v-if="obj.mime_type" class="text-xs font-mono shrink-0" style="color:var(--text-muted);">{{ obj.mime_type }}</span>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button class="btn-ghost" @click="downloadObject(bucket, obj)">Download</button>
                                        <button class="btn-ghost-danger" @click="deleteObject(bucket, obj)">Delete</button>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div class="flex items-center gap-2 flex-wrap">
                            <input type="file" :ref="el => setFileInput(bucket.id, el)" class="text-xs" style="color:var(--text-muted);" />
                            <input v-model="ui(bucket.id).uploadPath" placeholder="path (optional, defaults to file name)" class="input flex-1 min-w-[160px]" />
                            <button class="btn-accent" :disabled="ui(bucket.id).uploading" @click="uploadObject(bucket)">
                                {{ ui(bucket.id).uploading ? 'Uploading…' : '+ Upload' }}
                            </button>
                        </div>
                    </div>

                    <!-- Policies -->
                    <div class="px-5 py-4" style="border-top:1px solid var(--border);">
                        <p class="font-head font-medium text-xs uppercase tracking-widest mb-2" style="color:var(--text-muted);">Storage Policies</p>

                        <div v-if="!ui(bucket.id).policiesLoaded" class="text-xs" style="color:var(--text-muted);">Loading…</div>
                        <div v-else>
                            <div v-if="ui(bucket.id).policies.length === 0" class="text-xs mb-3" style="color:var(--text-muted);">
                                No policies — every <span class="font-mono">storage:*</span>-scoped API key can do anything through the passthrough.
                            </div>
                            <div v-for="pol in ui(bucket.id).policies" :key="pol.id" class="flex items-center justify-between px-3 py-2 mb-2 rounded-md" style="border:1px solid var(--border);">
                                <div class="text-xs font-mono" style="color:var(--text-main);">
                                    <span class="font-head font-medium uppercase">{{ pol.operation }}</span>
                                    <span v-if="pol.name" style="color:var(--text-muted);"> · {{ pol.name }}</span>
                                    <span style="color:var(--text-muted);"> · {{ pol.expression }}</span>
                                </div>
                                <button class="btn-ghost-danger" @click="deletePolicy(bucket, pol)">Delete</button>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap mt-2">
                                <input v-model="ui(bucket.id).newPolicy.name" placeholder="policy name" class="input w-32" />
                                <select v-model="ui(bucket.id).newPolicy.operation" class="input w-28">
                                    <option v-for="op in rlsOperations" :key="op" :value="op">{{ op }}</option>
                                </select>
                            </div>
                            <textarea v-model="ui(bucket.id).newPolicy.expression" rows="2" class="input mt-2 font-mono" placeholder="owner_id = $auth.id"></textarea>
                            <p class="text-xs mt-1" style="color:var(--text-muted);">
                                Raw SQL boolean expression over an object's columns
                                (<span class="font-mono">id, bucket_id, path, owner_id, size, mime_type, created_at, updated_at</span>).
                                Placeholders: <code v-for="ph in authPlaceholders" :key="ph" class="font-mono mr-1" style="color:var(--accent);">{{ ph }}</code>
                                — applies only to the passthrough API (<span class="font-mono">storage:*</span> keys / end users), never to this dashboard.
                            </p>
                            <div class="flex items-center gap-2 mt-2">
                                <button class="btn-accent" @click="addPolicy(bucket)">+ Add Policy</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- NEW BUCKET MODAL -->
        <div v-if="bucketModal" class="modal-backdrop" @click.self="bucketModal = false">
            <div class="modal-card">
                <p class="modal-title">New Bucket</p>
                <label class="field-label">Name<input v-model="bucketForm.name" class="input mt-1" placeholder="e.g. avatars" /></label>
                <label class="flex items-center gap-2 text-xs mt-3" style="color:var(--text-main);">
                    <input type="checkbox" v-model="bucketForm.public" /> Public (files served with no authentication)
                </label>
                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn-ghost" @click="bucketModal = false">Cancel</button>
                    <button class="btn-accent" @click="createBucket">Create</button>
                </div>
            </div>
        </div>

        <!-- IMAGE PREVIEW MODAL -->
        <div v-if="previewState.show" class="modal-backdrop" @click.self="closePreview">
            <div class="modal-card max-w-2xl text-center">
                <p class="modal-title">{{ previewState.path }}</p>
                <img v-if="previewState.url" :src="previewState.url" class="max-w-full max-h-[70vh] mx-auto rounded-md" />
                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn-ghost" @click="closePreview">Close</button>
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
            const rlsOperations = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALL'];
            const authPlaceholders = ['$auth.id', '$auth.email', '$auth.role'];

            const project = Vue.ref(null);
            const buckets = Vue.ref([]);
            const loading = Vue.ref(true);

            const bucketModal = Vue.ref(false);
            const bucketForm = Vue.reactive({ name: '', public: false });

            const previewState = Vue.reactive({ show: false, path: '', url: null });

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

            function isImage(obj) {
                return (obj.mime_type || '').startsWith('image/');
            }

            const bucketUiMap = Vue.reactive({});
            const fileInputs = {};
            function setFileInput(bucketId, el) { fileInputs[bucketId] = el; }
            function ui(bucketId) {
                if (!bucketUiMap[bucketId]) {
                    bucketUiMap[bucketId] = {
                        expanded: false,
                        objects: [],
                        objectsLoaded: false,
                        uploadPath: '',
                        uploading: false,
                        policies: [],
                        policiesLoaded: false,
                        newPolicy: { name: '', operation: 'SELECT', expression: '' },
                    };
                }
                return bucketUiMap[bucketId];
            }

            async function loadProjectHeader() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}`);
                    project.value = body;
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function loadBuckets() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/storage/buckets`);
                    buckets.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function openBucketModal() {
                bucketForm.name = '';
                bucketForm.public = false;
                bucketModal.value = true;
            }

            async function createBucket() {
                if (!bucketForm.name.trim()) { toast.error('Name is required'); return; }
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/storage/buckets`, {
                        method: 'POST',
                        body: JSON.stringify({ name: bucketForm.name, public: bucketForm.public }),
                    });
                    bucketModal.value = false;
                    toast.success('Bucket created');
                    await loadBuckets();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function togglePublic(bucket) {
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${bucket.id}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ public: !bucket.public }),
                    });
                    toast.success('Bucket updated');
                    await loadBuckets();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deleteBucket(bucket) {
                askConfirm(`Delete bucket "${bucket.name}" and all its files?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${bucket.id}`, { method: 'DELETE' });
                        toast.success('Bucket deleted');
                        await loadBuckets();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            function toggleBucket(bucket) {
                const state = ui(bucket.id);
                state.expanded = !state.expanded;
                if (state.expanded) {
                    if (!state.objectsLoaded) loadObjects(bucket);
                    if (!state.policiesLoaded) loadPolicies(bucket);
                }
            }

            async function loadObjects(bucket) {
                const state = ui(bucket.id);
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${bucket.id}/objects`);
                    state.objects = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    state.objectsLoaded = true;
                }
            }

            async function uploadObject(bucket) {
                const state = ui(bucket.id);
                const input = fileInputs[bucket.id];
                const file = input && input.files && input.files[0];
                if (!file) { toast.error('Choose a file first'); return; }

                const form = new FormData();
                form.append('file', file);
                if (state.uploadPath.trim()) form.append('path', state.uploadPath.trim());

                state.uploading = true;
                try {
                    const headers = { Authorization: 'Bearer ' + store.auth.token };
                    const res = await fetch(`/api/v1/projects/${PROJECT_ID}/storage/buckets/${bucket.id}/objects`, {
                        method: 'POST', headers, body: form,
                    });
                    const responseBody = await res.json().catch(() => null);
                    if (!res.ok) throw new Error((responseBody && responseBody.error) || `Upload failed (${res.status})`);

                    state.uploadPath = '';
                    if (input) input.value = '';
                    toast.success('File uploaded');
                    await loadObjects(bucket);
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    state.uploading = false;
                }
            }

            async function fetchObjectBlob(bucket, obj) {
                const headers = { Authorization: 'Bearer ' + store.auth.token };
                const res = await fetch(`/api/v1/projects/${PROJECT_ID}/storage/buckets/${bucket.id}/objects/${obj.id}/download`, { headers });
                if (!res.ok) throw new Error(`Download failed (${res.status})`);
                return res.blob();
            }

            async function downloadObject(bucket, obj) {
                try {
                    const blob = await fetchObjectBlob(bucket, obj);
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

            async function previewObject(bucket, obj) {
                previewState.path = obj.path;
                previewState.url = null;
                previewState.show = true;
                try {
                    const blob = await fetchObjectBlob(bucket, obj);
                    previewState.url = URL.createObjectURL(blob);
                } catch (e) {
                    toast.error(e.message);
                    previewState.show = false;
                }
            }

            function closePreview() {
                if (previewState.url) URL.revokeObjectURL(previewState.url);
                previewState.show = false;
                previewState.url = null;
            }

            function deleteObject(bucket, obj) {
                askConfirm(`Delete "${obj.path}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${bucket.id}/objects/${obj.id}`, { method: 'DELETE' });
                        toast.success('File deleted');
                        await loadObjects(bucket);
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            async function loadPolicies(bucket) {
                const state = ui(bucket.id);
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${bucket.id}/policies`);
                    state.policies = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    state.policiesLoaded = true;
                }
            }

            async function addPolicy(bucket) {
                const state = ui(bucket.id);
                const form = state.newPolicy;
                if (!form.name.trim()) { toast.error('Policy name is required'); return; }
                if (!form.expression.trim()) { toast.error('Expression is required'); return; }

                try {
                    await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${bucket.id}/policies`, {
                        method: 'POST',
                        body: JSON.stringify({
                            name: form.name,
                            operation: form.operation,
                            expression: form.expression,
                        }),
                    });
                    state.newPolicy = { name: '', operation: 'SELECT', expression: '' };
                    toast.success('Policy created');
                    await loadPolicies(bucket);
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function deletePolicy(bucket, policy) {
                askConfirm(`Delete policy "${policy.name}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/storage/buckets/${bucket.id}/policies/${policy.id}`, { method: 'DELETE' });
                        toast.success('Policy deleted');
                        await loadPolicies(bucket);
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadProjectHeader();
            loadBuckets();

            return {
                project, buckets, loading, rlsOperations, authPlaceholders,
                bucketModal, bucketForm, previewState, confirmState, ui, setFileInput,
                formatSize, isImage,
                openBucketModal, createBucket, togglePublic, deleteBucket, toggleBucket,
                uploadObject, downloadObject, previewObject, closePreview, deleteObject,
                addPolicy, deletePolicy,
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
        'title'     => 'Storage — Loxodontu',
        'pageTitle' => 'Storage',
    ],
];

include t('layouts/dashboard');
