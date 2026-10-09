<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <div class="mb-6">
        <h1 class="page-title">Functions</h1>
        <p class="page-subtitle">Project-scoped PHP functions exposed over HTTP.</p>
    </div>

    <div class="listbar">
        <input v-model="search" class="input" style="max-width:20rem;" placeholder="Search functions…" />
        <div class="flex-1"></div>
        <button class="btn-accent" @click="openNewModal">+ New Function</button>
    </div>

    <div v-if="loading" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card" v-for="n in 3" :key="n"><div class="skeleton" style="height:3rem;"></div></div>
    </div>
    <div v-else-if="filtered.length === 0" class="empty-state">
        <p class="empty-state-title">No functions yet</p>
        <p class="empty-state-body">Create a function to expose custom PHP logic over HTTP.</p>
        <button class="btn-accent" @click="openNewModal">+ New Function</button>
    </div>
    <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a v-for="fn in filtered" :key="fn.id" :href="`/dashboard/projects/${PROJECT_ID}/functions/${fn.id}`" class="card card-hover" style="text-decoration:none;">
            <div class="card-header">
                <span class="font-head font-medium text-sm" style="color:var(--text-main);">{{ fn.name }}</span>
                <span class="badge" :class="fn.enabled ? 'badge-success' : 'badge-danger'">{{ fn.enabled ? 'Enabled' : 'Disabled' }}</span>
            </div>
            <p class="text-xs font-mono" style="color:var(--text-muted);">/{{ fn.slug }}</p>
        </a>
    </div>

    <!-- NEW FUNCTION MODAL -->
    <div v-if="modal" class="modal-backdrop" @click.self="modal = false" data-dismiss-on-esc>
        <div class="modal-card max-w-lg">
            <p class="modal-title">New Function</p>
            <label class="field-label">Name<input v-model="form.name" class="input mt-1" placeholder="e.g. Stripe Webhook" /></label>
            <label class="field-label mt-3">Slug<input v-model="form.slug" class="input mt-1" placeholder="stripe-webhook" /></label>
            <label class="field-label mt-3">Source code<textarea v-model="form.source_code" rows="8" class="input mt-1 font-mono"></textarea></label>
            <div class="flex justify-end gap-2 mt-4">
                <button class="btn-ghost" @click="modal = false">Cancel</button>
                <button class="btn-accent" @click="create">Create</button>
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

            const functions = Vue.ref([]);
            const loading = Vue.ref(true);
            const search = Vue.ref('');
            const modal = Vue.ref(false);
            const form = Vue.reactive({
                name: '', slug: '',
                source_code: "<?php\n\nreturn function ($request) {\n    return ['message' => 'Hello from a Loxodontu function'];\n};\n",
            });

            const filtered = Vue.computed(() => {
                const q = search.value.trim().toLowerCase();
                if (!q) return functions.value;
                return functions.value.filter((f) => f.name.toLowerCase().includes(q) || f.slug.toLowerCase().includes(q));
            });

            async function load() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/functions`);
                    functions.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function openNewModal() {
                form.name = '';
                form.slug = '';
                modal.value = true;
            }

            async function create() {
                if (!form.name.trim() || !form.slug.trim()) { toast.error('Name and slug are required'); return; }
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/functions`, {
                        method: 'POST',
                        body: JSON.stringify({ name: form.name, slug: form.slug, source_code: form.source_code }),
                    });
                    modal.value = false;
                    toast.success('Function created');
                    await load();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            load();

            return { functions, loading, search, filtered, modal, form, openNewModal, create, PROJECT_ID };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Functions — Loxodontu',
        'pageTitle' => 'Functions',
    ],
];

include t('layouts/dashboard');
