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
            <a class="subnav-item active" href="<?= e($projectBase) ?>/auth/templates">Templates</a>
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/settings">Settings</a>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading&hellip;</div>
        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <a v-for="tpl in templates" :key="tpl.template_key" :href="`<?= e($projectBase) ?>/auth/templates/${tpl.template_key}`" class="card card-hover" style="text-decoration:none;">
                <div class="card-header">
                    <p class="font-medium">{{ labels[tpl.template_key].name }}</p>
                    <span :class="['badge', tpl.is_custom ? 'badge-accent' : '']">{{ tpl.is_custom ? 'Custom' : 'Default' }}</span>
                </div>
                <p class="text-sm" style="color:var(--text-muted);">{{ labels[tpl.template_key].description }}</p>
            </a>
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

            const PROJECT_ID = Vue.inject('projectId');
            const labels = {
                magic_link: { name: 'Magic Link', description: 'Sent for passwordless sign-in.' },
                password_reset: { name: 'Password Reset', description: 'Sent when an end user requests a password reset.' },
                email_verification: { name: 'Email Verification', description: 'Sent to confirm a new or unverified email address.' },
                email_change: { name: 'Email Change', description: 'Sent to the new address to confirm an email change.' },
            };

            const templates = Vue.ref([]);
            const loading = Vue.ref(true);

            async function loadTemplates() {
                loading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/templates`);
                    templates.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            loadTemplates();

            return { templates, loading, labels };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Auth · Templates — Loxodontu',
        'pageTitle' => 'Auth',
    ],
];

include t('layouts/dashboard');
