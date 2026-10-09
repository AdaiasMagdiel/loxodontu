<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <div class="mb-6">
        <h1 class="page-title">Cron Jobs</h1>
        <p class="page-subtitle">Scheduled jobs with retry and recurring execution.</p>
    </div>

    <div class="listbar">
        <input v-model="search" class="input" style="max-width:20rem;" placeholder="Search cron jobs…" />
        <div class="flex-1"></div>
        <button class="btn-accent" @click="openNewModal">+ New Cron Job</button>
    </div>

    <div v-if="loading" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card" v-for="n in 3" :key="n"><div class="skeleton" style="height:3rem;"></div></div>
    </div>
    <div v-else-if="filtered.length === 0" class="empty-state">
        <p class="empty-state-title">No cron jobs yet</p>
        <p class="empty-state-body">Schedule a job to run on an interval or at a specific time.</p>
        <button class="btn-accent" @click="openNewModal">+ New Cron Job</button>
    </div>
    <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a v-for="job in filtered" :key="job.id" :href="`/dashboard/projects/${PROJECT_ID}/cron-jobs/${job.id}`" class="card card-hover" style="text-decoration:none;">
            <div class="card-header">
                <span class="font-head font-medium text-sm" style="color:var(--text-main);">{{ job.name }}</span>
                <span class="badge" :class="statusBadgeClass(job)">{{ job.enabled ? (job.last_status || 'pending') : 'disabled' }}</span>
            </div>
            <p class="text-xs font-mono" style="color:var(--text-muted);">{{ job.type }} · {{ job.target }}</p>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Last run: {{ job.last_run_at ? formatDate(job.last_run_at) : 'never' }}</p>
        </a>
    </div>

    <!-- NEW CRON JOB MODAL -->
    <div v-if="modal" class="modal-backdrop" @click.self="modal = false" data-dismiss-on-esc>
        <div class="modal-card">
            <p class="modal-title">New Cron Job</p>
            <label class="field-label">Name<input v-model="form.name" class="input mt-1" placeholder="e.g. Nightly Report" /></label>
            <label class="field-label mt-3">Type
                <select v-model="form.type" class="input mt-1">
                    <option value="http">HTTP</option>
                    <option value="function">Function</option>
                </select>
            </label>
            <label class="field-label mt-3">{{ form.type === 'http' ? 'Target URL' : 'Function slug' }}
                <input v-model="form.target" class="input mt-1" :placeholder="form.type === 'http' ? 'https://example.com/webhook' : 'my-function'" />
            </label>
            <label class="field-label mt-3">Run every (minutes)<input v-model.number="form.intervalMinutes" type="number" min="1" class="input mt-1" /></label>
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

            const jobs = Vue.ref([]);
            const loading = Vue.ref(true);
            const search = Vue.ref('');
            const modal = Vue.ref(false);
            const form = Vue.reactive({ name: '', type: 'http', target: '', intervalMinutes: 60 });

            const filtered = Vue.computed(() => {
                const q = search.value.trim().toLowerCase();
                if (!q) return jobs.value;
                return jobs.value.filter((j) => j.name.toLowerCase().includes(q));
            });

            function statusBadgeClass(job) {
                if (!job.enabled) return '';
                if (job.last_status === 'success') return 'badge-success';
                if (job.last_status === 'failed') return 'badge-danger';
                return 'badge-accent';
            }

            function formatDate(iso) {
                return new Date(iso).toLocaleString();
            }

            async function load() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/cron-jobs`);
                    jobs.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function openNewModal() {
                form.name = ''; form.type = 'http'; form.target = ''; form.intervalMinutes = 60;
                modal.value = true;
            }

            async function create() {
                if (!form.name.trim() || !form.target.trim()) { toast.error('Name and target are required'); return; }
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/cron-jobs`, {
                        method: 'POST',
                        body: JSON.stringify({
                            name: form.name,
                            type: form.type,
                            target: form.target,
                            interval_seconds: Math.max(60, form.intervalMinutes * 60),
                        }),
                    });
                    modal.value = false;
                    toast.success('Cron job created');
                    await load();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            load();

            return { jobs, loading, search, filtered, modal, form, openNewModal, create, statusBadgeClass, formatDate, PROJECT_ID };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Cron Jobs — Loxodontu',
        'pageTitle' => 'Cron Jobs',
    ],
];

include t('layouts/dashboard');
