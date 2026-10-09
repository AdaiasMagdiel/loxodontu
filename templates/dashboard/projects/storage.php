<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <div class="mb-6">
        <h1 class="page-title">Storage</h1>
        <p class="page-subtitle">Buckets group files with their own access policies.</p>
    </div>

    <div class="listbar">
        <input v-model="search" class="input" style="max-width:20rem;" placeholder="Search buckets…" />
        <div class="flex-1"></div>
        <button class="btn-accent" @click="openBucketModal">+ New Bucket</button>
    </div>

    <div v-if="loading" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card" v-for="n in 3" :key="n"><div class="skeleton" style="height:3rem;"></div></div>
    </div>
    <div v-else-if="filteredBuckets.length === 0" class="empty-state">
        <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7 12 3l9 4-9 4-9-4Z" /><path d="M3 7v10l9 4 9-4V7" /><path d="M12 11v10" /></svg>
        <p class="empty-state-title">No buckets yet</p>
        <p class="empty-state-body">Buckets group files with their own access policies.</p>
        <button class="btn-accent" @click="openBucketModal">+ New Bucket</button>
    </div>
    <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a v-for="bucket in filteredBuckets" :key="bucket.id" :href="`/dashboard/projects/${PROJECT_ID}/storage/${bucket.id}`" class="card card-hover" style="text-decoration:none;">
            <div class="card-header">
                <span class="font-head font-medium text-sm" style="color:var(--text-main);">{{ bucket.name }}</span>
                <span class="badge" :class="bucket.public ? 'badge-accent' : ''">{{ bucket.public ? 'Public' : 'Private' }}</span>
            </div>
            <p class="text-xs" style="color:var(--text-muted);">Created {{ formatDate(bucket.created_at) }}</p>
        </a>
    </div>

    <!-- NEW BUCKET MODAL -->
    <div v-if="bucketModal" class="modal-backdrop" @click.self="bucketModal = false" data-dismiss-on-esc>
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

            const buckets = Vue.ref([]);
            const loading = Vue.ref(true);
            const search = Vue.ref('');

            const bucketModal = Vue.ref(false);
            const bucketForm = Vue.reactive({ name: '', public: false });

            const filteredBuckets = Vue.computed(() => {
                const q = search.value.trim().toLowerCase();
                if (!q) return buckets.value;
                return buckets.value.filter((b) => b.name.toLowerCase().includes(q));
            });

            function formatDate(iso) {
                return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
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

            loadBuckets();

            return { buckets, loading, search, filteredBuckets, formatDate, bucketModal, bucketForm, openBucketModal, createBucket };
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
