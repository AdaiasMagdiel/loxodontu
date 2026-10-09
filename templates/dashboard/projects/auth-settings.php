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
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/providers">Providers</a>
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/templates">Templates</a>
            <a class="subnav-item active" href="<?= e($projectBase) ?>/auth/settings">Settings</a>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading&hellip;</div>
        <template v-else>
            <div v-if="!config.from_address" class="empty-state mb-4">
                <p class="empty-state-title">No email provider configured yet</p>
                <p class="empty-state-body">Set up SMTP or Resend on the Providers tab before requiring email confirmation.</p>
                <a class="btn-accent" style="text-decoration:none;" :href="`<?= e($projectBase) ?>/auth/providers`">Go to Providers</a>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <p class="font-medium">Require email confirmation</p>
                        <p class="text-xs" style="color:var(--text-muted);">End users must confirm their email before they can log in.</p>
                    </div>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" v-model="requireConfirmation" :disabled="!config.from_address" />
                    </label>
                </div>
            </div>

            <div class="editor-savebar">
                <span class="text-xs" style="color:var(--text-muted);">Changes apply immediately.</span>
                <button class="btn-accent" :disabled="!config.from_address" @click="save">Save changes</button>
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
            const config = Vue.ref({});
            const requireConfirmation = Vue.ref(false);

            async function load() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/email-config`);
                    config.value = body;
                    requireConfirmation.value = body.require_email_confirmation;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            async function save() {
                try {
                    // EmailConfig::update always requires provider+from_address, so the
                    // existing config is sent back unchanged alongside the one field
                    // this page actually edits; secrets are omitted so they're kept.
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/email-config`, {
                        method: 'PUT',
                        body: JSON.stringify({
                            provider: config.value.provider,
                            from_address: config.value.from_address,
                            from_name: config.value.from_name,
                            smtp_host: config.value.smtp_host,
                            smtp_port: config.value.smtp_port,
                            smtp_username: config.value.smtp_username,
                            smtp_encryption: config.value.smtp_encryption,
                            require_email_confirmation: requireConfirmation.value,
                        }),
                    });
                    config.value = body;
                    toast.success('Settings saved');
                } catch (e) {
                    toast.error(e.message);
                }
            }

            load();

            return { loading, config, requireConfirmation, save };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Auth · Settings — Loxodontu',
        'pageTitle' => 'Auth',
    ],
];

include t('layouts/dashboard');
