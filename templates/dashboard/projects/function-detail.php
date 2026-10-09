<?php
/** @var int $projectId */
/** @var int $functionId */
ob_start();
?>
<template id="tpl-page">
    <nav class="breadcrumb">
        <a :href="`/dashboard/projects/${PROJECT_ID}`">Overview</a>
        <span class="breadcrumb-sep">/</span>
        <a :href="`/dashboard/projects/${PROJECT_ID}/functions`">Functions</a>
        <span class="breadcrumb-sep">/</span>
        <span class="breadcrumb-current">{{ fn ? fn.name : '…' }}</span>
    </nav>

    <div class="mb-6 flex items-center gap-3">
        <h1 class="page-title">{{ fn ? fn.name : '…' }}</h1>
        <span v-if="fn" class="badge" :class="fn.enabled ? 'badge-success' : 'badge-danger'">{{ fn.enabled ? 'Enabled' : 'Disabled' }}</span>
    </div>
    <p v-if="fn" class="page-subtitle mb-6 font-mono">/{{ fn.slug }}</p>

    <div class="subnav">
        <button class="subnav-item" :class="{ active: tab === 'code' }" @click="tab = 'code'">Code</button>
        <button class="subnav-item" :class="{ active: tab === 'test' }" @click="tab = 'test'">Test</button>
        <button class="subnav-item" :class="{ active: tab === 'logs' }" @click="tab = 'logs'">Logs</button>
    </div>

    <!-- CODE TAB -->
    <div v-show="tab === 'code'">
        <div class="card">
            <textarea v-model="sourceCode" rows="16" class="input font-mono" style="min-height:24rem;"></textarea>
            <div class="flex justify-end mt-3">
                <button class="btn-accent" :disabled="saving" @click="saveCode">{{ saving ? 'Saving…' : 'Save' }}</button>
            </div>
        </div>
    </div>

    <!-- TEST TAB -->
    <div v-show="tab === 'test'">
        <div class="card mb-4">
            <label class="field-label">Request body (JSON)<textarea v-model="testBody" rows="4" class="input mt-1 font-mono"></textarea></label>
            <div class="flex justify-end mt-3">
                <button class="btn-accent" :disabled="testing" @click="sendTest">{{ testing ? 'Sending…' : 'Send test request' }}</button>
            </div>
        </div>
        <div v-if="testResult" class="card">
            <p class="field-label mb-2">
                Response · <span :class="testResult.status < 400 ? '' : ''" :style="testResult.status < 400 ? 'color:var(--success);' : 'color:var(--danger);'">{{ testResult.status }}</span> · {{ testResult.duration }}ms
            </p>
            <pre class="font-mono text-xs" style="white-space:pre-wrap;color:var(--text-main);">{{ testResult.body }}</pre>
        </div>
    </div>

    <!-- LOGS TAB -->
    <div v-show="tab === 'logs'">
        <div class="empty-state">
            <p class="empty-state-title">Logs aren't available yet</p>
            <p class="empty-state-body">Invocation logging isn't implemented in this project yet.</p>
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
            const FUNCTION_ID = <?= json_encode((string) $functionId) ?>;

            const fn = Vue.ref(null);
            const tab = Vue.ref('code');
            const sourceCode = Vue.ref('');
            const saving = Vue.ref(false);
            const testBody = Vue.ref('{}');
            const testing = Vue.ref(false);
            const testResult = Vue.ref(null);

            async function load() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/functions/${FUNCTION_ID}`);
                    fn.value = body;
                    sourceCode.value = body.source_code || '';
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function saveCode() {
                saving.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/functions/${FUNCTION_ID}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ source_code: sourceCode.value }),
                    });
                    fn.value = body;
                    toast.success('Saved');
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    saving.value = false;
                }
            }

            async function sendTest() {
                testing.value = true;
                testResult.value = null;
                const start = performance.now();
                try {
                    let payload = {};
                    try { payload = testBody.value.trim() ? JSON.parse(testBody.value) : {}; }
                    catch (e) { toast.error('Request body must be valid JSON'); testing.value = false; return; }

                    const res = await fetch(`/api/v1/${PROJECT_ID}/functions/${fn.value.slug}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + store.auth.token },
                        body: JSON.stringify(payload),
                    });
                    const text = await res.text();
                    testResult.value = { status: res.status, duration: Math.round(performance.now() - start), body: text };
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    testing.value = false;
                }
            }

            load();

            return { PROJECT_ID, fn, tab, sourceCode, saving, saveCode, testBody, testing, testResult, sendTest };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Function — Loxodontu',
        'pageTitle' => 'Functions',
    ],
];

include t('layouts/dashboard');
