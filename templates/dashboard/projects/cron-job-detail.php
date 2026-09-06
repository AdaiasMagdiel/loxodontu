<?php
/** @var int $projectId */
/** @var int $jobId */
ob_start();
?>
<template id="tpl-page">
    <nav class="breadcrumb">
        <a :href="`/dashboard/projects/${PROJECT_ID}`">Overview</a>
        <span class="breadcrumb-sep">/</span>
        <a :href="`/dashboard/projects/${PROJECT_ID}/cron-jobs`">Cron Jobs</a>
        <span class="breadcrumb-sep">/</span>
        <span class="breadcrumb-current">{{ job ? job.name : '…' }}</span>
    </nav>

    <div class="mb-6">
        <h1 class="page-title">{{ job ? job.name : '…' }}</h1>
        <p v-if="job" class="page-subtitle font-mono">{{ job.type }} · {{ job.target }}</p>
    </div>

    <div class="subnav">
        <button class="subnav-item" :class="{ active: tab === 'overview' }" @click="tab = 'overview'">Overview</button>
        <button class="subnav-item" :class="{ active: tab === 'runs' }" @click="switchToRuns">Run History</button>
    </div>

    <!-- OVERVIEW TAB -->
    <div v-show="tab === 'overview'" v-if="form">
        <div class="card mb-6">
            <label class="field-label">Name<input v-model="form.name" class="input mt-1" /></label>
            <label class="field-label mt-3">Target<input v-model="form.target" class="input mt-1 font-mono" /></label>
            <label class="flex items-center gap-2 text-sm mt-3" style="color:var(--text-main);">
                <input type="checkbox" v-model="form.enabled" /> Enabled
            </label>
            <div class="flex justify-end mt-4">
                <button class="btn-accent" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</button>
            </div>
        </div>
        <div class="card" style="border-color:var(--danger);">
            <p class="field-label mb-3" style="color:var(--danger);">Danger Zone</p>
            <button class="btn-ghost-danger" @click="destroy">Delete cron job</button>
        </div>
    </div>

    <!-- RUN HISTORY TAB -->
    <div v-show="tab === 'runs'">
        <div v-if="!runsLoaded" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <template v-else>
            <div v-if="runs.length === 0" class="empty-state">
                <p class="empty-state-title">No runs yet</p>
            </div>
            <div v-else class="datalist">
                <div class="datalist-head"><div class="datalist-cell">Status</div><div class="datalist-cell">Started</div><div class="datalist-cell" style="flex:0 0 8rem;">Duration</div></div>
                <div v-for="run in runs" :key="run.id" class="datalist-row">
                    <div class="datalist-cell"><span class="badge" :class="run.status === 'success' ? 'badge-success' : (run.status === 'failed' ? 'badge-danger' : 'badge-accent')">{{ run.status }}</span></div>
                    <div class="datalist-cell">{{ formatDate(run.started_at) }}</div>
                    <div class="datalist-cell" style="flex:0 0 8rem;">{{ run.duration_ms !== null ? run.duration_ms + 'ms' : '—' }}</div>
                </div>
            </div>
            <div class="pagination">
                <span class="pagination-info">{{ runs.length === 0 ? 0 : runsOffset + 1 }}–{{ runsOffset + runs.length }} of {{ runsTotal }}</span>
                <div class="flex gap-2">
                    <button class="btn-ghost" :disabled="runsOffset === 0" @click="runsOffset = Math.max(0, runsOffset - runsLimit); loadRuns();">Previous</button>
                    <button class="btn-ghost" :disabled="runsOffset + runsLimit >= runsTotal" @click="runsOffset += runsLimit; loadRuns();">Next</button>
                </div>
            </div>
        </template>
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
            const JOB_ID = <?= json_encode((string) $jobId) ?>;

            const job = Vue.ref(null);
            const form = Vue.ref(null);
            const tab = Vue.ref('overview');
            const saving = Vue.ref(false);

            const runs = Vue.ref([]);
            const runsLoaded = Vue.ref(false);
            const runsTotal = Vue.ref(0);
            const runsLimit = 25;
            const runsOffset = Vue.ref(0);

            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });
            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            function formatDate(iso) {
                return new Date(iso).toLocaleString();
            }

            async function load() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/cron-jobs/${JOB_ID}`);
                    job.value = body;
                    form.value = { name: body.name, target: body.target, enabled: body.enabled };
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function save() {
                saving.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/cron-jobs/${JOB_ID}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ name: form.value.name, target: form.value.target, enabled: form.value.enabled }),
                    });
                    job.value = body;
                    toast.success('Saved');
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    saving.value = false;
                }
            }

            function destroy() {
                askConfirm(`Delete cron job "${job.value ? job.value.name : ''}"? This cannot be undone.`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/cron-jobs/${JOB_ID}`, { method: 'DELETE' });
                        toast.success('Cron job deleted');
                        location.href = `/dashboard/projects/${PROJECT_ID}/cron-jobs`;
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            async function loadRuns() {
                runsLoaded.value = false;
                try {
                    const { body, headers } = await apiFetch(`/projects/${PROJECT_ID}/cron-jobs/${JOB_ID}/runs?limit=${runsLimit}&offset=${runsOffset.value}`);
                    runs.value = body;
                    runsTotal.value = parseInt(headers.get('X-Total-Count') || '0', 10);
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    runsLoaded.value = true;
                }
            }

            function switchToRuns() {
                tab.value = 'runs';
                if (!runsLoaded.value) loadRuns();
            }

            load();

            return { PROJECT_ID, job, form, tab, saving, save, destroy, runs, runsLoaded, runsTotal, runsLimit, runsOffset, loadRuns, switchToRuns, formatDate, confirmState };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Cron Job — Loxodontu',
        'pageTitle' => 'Cron Jobs',
    ],
];

include t('layouts/dashboard');
