<?php
/** @var int $projectId */
ob_start();
?>
<template id="tpl-page">
    <main class="px-8 py-8 max-w-6xl mx-auto">
        <div class="mb-6">
            <a href="/dashboard/projects" class="text-xs font-head uppercase tracking-wide no-underline" style="color:var(--text-muted);">&larr; Projects</a>
            <h1 class="font-head font-bold text-2xl uppercase tracking-wide mt-1" style="color:var(--text-main);">{{ project ? project.name : '...' }}</h1>
        </div>

        <?php include t('dashboard/projects/_tabs'); ?>

        <div class="flex items-center justify-between mb-4">
            <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">Cron Jobs</p>
            <button class="btn-accent" @click="openCreate">New Cron Job</button>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading...</div>
        <div v-else-if="jobs.length === 0" class="rounded-lg p-8 text-center text-sm" style="background:var(--bg-surface); border:1px solid var(--border); color:var(--text-muted);">
            No cron jobs registered yet.
        </div>
        <div v-else class="rounded-lg overflow-hidden" style="border:1px solid var(--border);">
            <div v-for="job in jobs" :key="job.id" class="px-5 py-4" style="background:var(--bg-surface); border-bottom:1px solid var(--border);">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="font-head font-medium text-sm" style="color:var(--text-main);">{{ job.name }}</p>
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded" style="background:var(--bg-hover); color:var(--text-muted);">{{ job.type }}</span>
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded" style="background:var(--bg-hover); color:var(--text-muted);">{{ job.last_status }}</span>
                        </div>
                        <p class="text-xs font-mono mt-1" style="color:var(--text-muted);">{{ job.queue }} · {{ job.target }}</p>
                        <p class="text-xs mt-2" style="color:var(--text-muted);">Next: {{ formatDate(job.next_run_at) }} · Failures: {{ job.failure_count }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button class="btn-ghost" @click="editJob(job)">Edit</button>
                        <button class="btn-ghost" @click="loadRuns(job)">Runs</button>
                        <button class="btn-ghost-danger" @click="deleteJob(job)">Delete</button>
                    </div>
                </div>
                <div v-if="runsFor === job.id" class="mt-3 rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    <div v-for="run in runs" :key="run.id" class="px-3 py-2 text-xs font-mono" style="border-bottom:1px solid var(--border); color:var(--text-muted);">
                        {{ run.started_at }} · {{ run.status }} · attempt {{ run.attempt }} · {{ run.duration_ms || 0 }}ms
                        <span v-if="run.error"> · {{ run.error }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="modal" class="modal-backdrop" @click.self="modal = false">
            <div class="modal-card max-w-xl">
                <p class="modal-title">{{ form.id ? 'Edit Cron Job' : 'New Cron Job' }}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="field-label">Name<input v-model="form.name" class="input mt-1" /></label>
                    <label class="field-label">Queue<input v-model="form.queue" class="input mt-1 font-mono" /></label>
                    <label class="field-label">Type
                        <select v-model="form.type" class="input mt-1">
                            <option value="http">HTTP</option>
                            <option value="function">Function</option>
                            <option value="callback">Callback</option>
                            <option value="command">Command</option>
                        </select>
                    </label>
                    <label v-if="form.type === 'http'" class="field-label">Method<input v-model="form.method" class="input mt-1 font-mono" /></label>
                </div>
                <label class="field-label mt-3">Target
                    <select v-if="form.type === 'function'" v-model="form.target" class="input mt-1">
                        <option value="">Select function...</option>
                        <option v-for="fn in functions" :key="fn.id" :value="fn.slug">{{ fn.slug }}</option>
                    </select>
                    <input v-else v-model="form.target" class="input mt-1 font-mono" />
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                    <label class="field-label">Run at<input v-model="form.run_at" type="datetime-local" class="input mt-1" /></label>
                    <label class="field-label">Interval seconds<input v-model.number="form.interval_seconds" type="number" min="60" class="input mt-1" /></label>
                    <label class="field-label">Max retries<input v-model.number="form.max_retries" type="number" min="0" class="input mt-1" /></label>
                    <label class="field-label">Retry delay seconds<input v-model.number="form.retry_delay_seconds" type="number" min="1" class="input mt-1" /></label>
                </div>
                <label class="field-label mt-3">Payload<textarea v-model="form.payload" class="input font-mono mt-1 min-h-[96px]" spellcheck="false"></textarea></label>
                <div class="flex gap-4 mt-3">
                    <label class="flex items-center gap-2 text-xs" style="color:var(--text-main);"><input type="checkbox" v-model="form.enabled" /> Enabled</label>
                    <label class="flex items-center gap-2 text-xs" style="color:var(--text-main);"><input type="checkbox" v-model="form.allow_overlap" /> Allow overlap</label>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn-ghost" @click="modal = false">Cancel</button>
                    <button class="btn-accent" @click="saveJob">Save</button>
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
            const project = Vue.ref(null);
            const jobs = Vue.ref([]);
            const functions = Vue.ref([]);
            const loading = Vue.ref(true);
            const modal = Vue.ref(false);
            const runsFor = Vue.ref(null);
            const runs = Vue.ref([]);
            const form = Vue.reactive({ id: null, name: '', queue: 'default', type: 'function', target: '', method: 'POST', run_at: '', interval_seconds: 300, max_retries: 3, retry_delay_seconds: 300, payload: '{}', enabled: true, allow_overlap: false });

            function formatDate(v) { return v ? new Date(v.replace(' ', 'T')).toLocaleString() : '-'; }

            async function loadProjectHeader() {
                try { project.value = (await apiFetch(`/projects/${PROJECT_ID}`)).body; } catch (e) { toast.error(e.message); }
            }

            async function loadJobs() {
                loading.value = true;
                try { jobs.value = (await apiFetch(`/projects/${PROJECT_ID}/cron-jobs`)).body; } catch (e) { toast.error(e.message); } finally { loading.value = false; }
            }

            async function loadFunctions() {
                try { functions.value = (await apiFetch(`/projects/${PROJECT_ID}/functions`)).body; } catch (e) { toast.error(e.message); }
            }

            function resetForm() {
                Object.assign(form, { id: null, name: '', queue: 'default', type: 'function', target: '', method: 'POST', run_at: '', interval_seconds: 300, max_retries: 3, retry_delay_seconds: 300, payload: '{}', enabled: true, allow_overlap: false });
            }

            function openCreate() { resetForm(); modal.value = true; }

            function editJob(job) {
                Object.assign(form, {
                    id: job.id,
                    name: job.name,
                    queue: job.queue || 'default',
                    type: job.type,
                    target: job.target,
                    method: job.method || 'POST',
                    run_at: job.run_at ? job.run_at.replace(' ', 'T').slice(0, 16) : '',
                    interval_seconds: job.interval_seconds || '',
                    max_retries: job.max_retries ?? 3,
                    retry_delay_seconds: job.retry_delay_seconds || 300,
                    payload: job.payload || '{}',
                    enabled: job.enabled,
                    allow_overlap: job.allow_overlap,
                });
                modal.value = true;
            }

            async function saveJob() {
                if (!form.name.trim() || !form.target.trim()) { toast.error('Name and target are required'); return; }
                let payload = null;
                try { payload = form.payload.trim() ? JSON.parse(form.payload) : null; } catch (e) { toast.error('Payload must be valid JSON'); return; }
                const body = {
                    name: form.name,
                    queue: form.queue || 'default',
                    type: form.type,
                    target: form.target,
                    method: form.method || 'POST',
                    run_at: form.run_at ? form.run_at.replace('T', ' ') + ':00' : null,
                    interval_seconds: form.interval_seconds ? Number(form.interval_seconds) : null,
                    max_retries: form.max_retries,
                    retry_delay_seconds: form.retry_delay_seconds,
                    payload,
                    enabled: form.enabled,
                    allow_overlap: form.allow_overlap,
                };
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/cron-jobs${form.id ? '/' + form.id : ''}`, {
                        method: form.id ? 'PATCH' : 'POST',
                        body: JSON.stringify(body),
                    });
                    modal.value = false;
                    toast.success('Cron job saved');
                    await loadJobs();
                } catch (e) { toast.error(e.message); }
            }

            async function deleteJob(job) {
                if (!confirm(`Delete cron job "${job.name}"?`)) return;
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/cron-jobs/${job.id}`, { method: 'DELETE' });
                    toast.success('Cron job deleted');
                    await loadJobs();
                } catch (e) { toast.error(e.message); }
            }

            async function loadRuns(job) {
                if (runsFor.value === job.id) { runsFor.value = null; runs.value = []; return; }
                try {
                    runs.value = (await apiFetch(`/projects/${PROJECT_ID}/cron-jobs/${job.id}/runs`)).body;
                    runsFor.value = job.id;
                } catch (e) { toast.error(e.message); }
            }

            loadProjectHeader();
            loadFunctions();
            loadJobs();

            return { PROJECT_ID, project, jobs, functions, loading, modal, runsFor, runs, form, formatDate, openCreate, editJob, saveJob, deleteJob, loadRuns };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Cron Jobs - Loxodontu',
        'pageTitle' => 'Cron Jobs',
    ],
];

include t('layouts/dashboard');
