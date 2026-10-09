<?php
/** @var int $projectId */
$projectBase = '/dashboard/projects/' . $projectId;
ob_start();
?>
<template id="tpl-page">
    <main>
        <nav class="breadcrumb">
            <a href="<?= e($projectBase) ?>">Overview</a>
            <span class="breadcrumb-sep">/</span>
            <span class="breadcrumb-current">Auth</span>
        </nav>
        <h1 class="page-title mb-1">Auth</h1>
        <p class="page-subtitle mb-4">Manage sign-in, providers, email templates and end users.</p>

        <div class="subnav">
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/users">Users</a>
            <a class="subnav-item active" href="<?= e($projectBase) ?>/auth/providers">Providers</a>
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/templates">Templates</a>
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/settings">Settings</a>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading&hellip;</div>
        <template v-else>
            <div class="card">
                <div class="card-header">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                        <div>
                            <p class="font-medium">Email</p>
                            <p class="text-xs" style="color:var(--text-muted);">SMTP or Resend — used for magic links, password resets and email verification</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4 mb-4">
                    <label class="flex items-center gap-2 text-sm"><input type="radio" value="smtp" v-model="form.provider" /> SMTP</label>
                    <label class="flex items-center gap-2 text-sm"><input type="radio" value="resend" v-model="form.provider" /> Resend</label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                    <label class="field-label">From address<input v-model="form.from_address" class="input mt-1" placeholder="noreply@yourapp.com" /></label>
                    <label class="field-label">From name<input v-model="form.from_name" class="input mt-1" placeholder="Your App" /></label>
                </div>

                <template v-if="form.provider === 'smtp'">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                        <label class="field-label">SMTP host<input v-model="form.smtp_host" class="input mt-1" placeholder="smtp.yourapp.com" /></label>
                        <label class="field-label">SMTP port<input v-model.number="form.smtp_port" type="number" class="input mt-1" placeholder="587" /></label>
                        <label class="field-label">Username<input v-model="form.smtp_username" class="input mt-1" /></label>
                        <label class="field-label">Encryption
                            <select v-model="form.smtp_encryption" class="input mt-1">
                                <option value="none">None</option>
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                            </select>
                        </label>
                    </div>
                    <label class="field-label">Password<input v-model="form.smtp_password" type="password" class="input mt-1" :placeholder="config.has_smtp_password ? '&bull;&bull;&bull;&bull; (set — leave blank to keep)' : ''" /></label>
                </template>
                <template v-else>
                    <label class="field-label">API key<input v-model="form.resend_api_key" type="password" class="input mt-1" :placeholder="config.has_resend_api_key ? '&bull;&bull;&bull;&bull; (set — leave blank to keep)' : ''" /></label>
                </template>

                <div class="flex items-center gap-2 mt-4 flex-wrap">
                    <input v-model="testTo" class="input" style="max-width:16rem;" placeholder="you@example.com" />
                    <button class="btn-ghost" :disabled="sendingTest" @click="sendTest">{{ sendingTest ? 'Sending&hellip;' : 'Send test email' }}</button>
                </div>
            </div>

            <div class="editor-savebar mt-6">
                <span class="text-xs" style="color:var(--text-muted);">Saving updates delivery for magic links, password resets and email verification.</span>
                <button class="btn-accent" @click="save">Save changes</button>
            </div>
        </template>
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

            const PROJECT_ID = Vue.inject('projectId');

            const loading = Vue.ref(true);
            const sendingTest = Vue.ref(false);
            const testTo = Vue.ref('');
            const config = Vue.ref({});
            const form = Vue.reactive({
                provider: 'smtp', from_address: '', from_name: '',
                smtp_host: '', smtp_port: 587, smtp_username: '', smtp_encryption: 'tls',
                smtp_password: '', resend_api_key: '',
            });

            async function loadConfig() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/email-config`);
                    config.value = body;
                    Object.assign(form, body, { smtp_password: '', resend_api_key: '' });
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            async function save() {
                if (!form.from_address.trim()) { toast.error('From address is required'); return; }
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/email-config`, {
                        method: 'PUT',
                        body: JSON.stringify(form),
                    });
                    config.value = body;
                    Object.assign(form, body, { smtp_password: '', resend_api_key: '' });
                    toast.success('Providers saved');
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function sendTest() {
                if (!testTo.value.trim()) { toast.error('Enter an address to send to'); return; }
                sendingTest.value = true;
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/auth/email-config/test`, {
                        method: 'POST',
                        body: JSON.stringify({ to: testTo.value.trim() }),
                    });
                    toast.success('Test email sent');
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    sendingTest.value = false;
                }
            }

            loadConfig();

            return { loading, config, form, testTo, sendingTest, save, sendTest };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Auth · Providers — Loxodontu',
        'pageTitle' => 'Auth',
    ],
];

include t('layouts/dashboard');
