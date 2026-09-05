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

        <!-- EMAIL PROVIDER -->
        <div class="rounded-lg p-5 mb-6" style="background:var(--bg-surface); border:1px solid var(--border);">
            <p class="font-head font-medium text-xs uppercase tracking-widest mb-4" style="color:var(--text-muted);">Email Provider</p>

            <div v-if="configLoading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
            <template v-else>
                <div class="flex items-center gap-4 mb-4">
                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-main);">
                        <input type="radio" value="smtp" v-model="configForm.provider" /> SMTP
                    </label>
                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-main);">
                        <input type="radio" value="resend" v-model="configForm.provider" /> Resend
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <label class="field-label">From address<input v-model="configForm.from_address" class="input mt-1" placeholder="noreply@yourapp.com" /></label>
                    <label class="field-label">From name<input v-model="configForm.from_name" class="input mt-1" placeholder="Your App" /></label>
                </div>

                <template v-if="configForm.provider === 'smtp'">
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <label class="field-label">SMTP host<input v-model="configForm.smtp_host" class="input mt-1" placeholder="smtp.yourapp.com" /></label>
                        <label class="field-label">SMTP port<input v-model.number="configForm.smtp_port" type="number" class="input mt-1" placeholder="587" /></label>
                        <label class="field-label">Username<input v-model="configForm.smtp_username" class="input mt-1" /></label>
                        <label class="field-label">Encryption
                            <select v-model="configForm.smtp_encryption" class="input mt-1">
                                <option value="none">None</option>
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                            </select>
                        </label>
                    </div>
                    <label class="field-label">Password<input v-model="configForm.smtp_password" type="password" class="input mt-1" :placeholder="config.has_smtp_password ? '•••• (set — leave blank to keep)' : ''" /></label>
                </template>

                <template v-else>
                    <label class="field-label">API key<input v-model="configForm.resend_api_key" type="password" class="input mt-1" :placeholder="config.has_resend_api_key ? '•••• (set — leave blank to keep)' : ''" /></label>
                </template>

                <label class="flex items-center gap-2 text-sm mt-4" style="color:var(--text-main);">
                    <input type="checkbox" v-model="configForm.require_email_confirmation" /> Require email confirmation before an end user can log in
                </label>

                <div class="flex items-center gap-2 mt-4">
                    <button class="btn-accent" @click="saveConfig">Save</button>
                    <input v-model="testTo" placeholder="you@example.com" class="input w-56" />
                    <button class="btn-ghost" :disabled="sendingTest" @click="sendTest">{{ sendingTest ? 'Sending…' : 'Send test email' }}</button>
                </div>
            </template>
        </div>

        <!-- TEMPLATES -->
        <div class="flex items-center justify-between mb-4">
            <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">Email Templates</p>
        </div>

        <div v-if="templatesLoading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
        <div v-else class="grid grid-cols-2 gap-3">
            <div v-for="tpl in templates" :key="tpl.template_key" class="rounded-lg p-4 cursor-pointer" style="background:var(--bg-surface); border:1px solid var(--border);" @click="openTemplate(tpl)">
                <div class="flex items-center justify-between">
                    <span class="font-head font-medium text-sm" style="color:var(--text-main);">{{ templateLabels[tpl.template_key] }}</span>
                    <span class="text-xs font-head uppercase tracking-wide px-2 py-0.5 rounded" :style="tpl.is_custom ? 'background:var(--accent-dim); color:var(--accent);' : 'border:1px solid var(--border); color:var(--text-muted);'">
                        {{ tpl.is_custom ? 'Custom' : 'Default' }}
                    </span>
                </div>
                <p class="text-xs mt-2 truncate" style="color:var(--text-muted);">{{ tpl.subject }}</p>
            </div>
        </div>

        <!-- TEMPLATE EDITOR MODAL -->
        <div v-if="templateModal" class="modal-backdrop" @click.self="templateModal = false">
            <div class="modal-card max-w-2xl">
                <p class="modal-title">{{ templateLabels[templateForm.template_key] }}</p>
                <label class="field-label">Subject<input v-model="templateForm.subject" class="input mt-1" /></label>
                <label class="field-label mt-3">Body<textarea v-model="templateForm.body" rows="8" class="input mt-1 font-mono"></textarea></label>
                <p class="text-xs mt-1" style="color:var(--text-muted);">
                    Placeholders: <code v-for="ph in templatePlaceholders" :key="ph" class="font-mono mr-1" style="color:var(--accent);">{{ '{{' + ph + '}}' }}</code>
                </p>

                <div v-if="preview" class="mt-4 p-3 rounded-md" style="border:1px solid var(--border);">
                    <p class="text-xs font-head uppercase tracking-wide mb-1" style="color:var(--text-muted);">Preview</p>
                    <p class="text-sm font-medium" style="color:var(--text-main);">{{ preview.subject }}</p>
                    <div class="text-sm mt-1" style="color:var(--text-main);" v-html="preview.body"></div>
                </div>

                <div class="flex justify-between items-center mt-4">
                    <button class="btn-ghost-danger" @click="resetTemplate">Reset to default</button>
                    <div class="flex gap-2">
                        <button class="btn-ghost" @click="previewTemplate">Preview</button>
                        <button class="btn-ghost" @click="templateModal = false">Cancel</button>
                        <button class="btn-accent" @click="saveTemplate">Save</button>
                    </div>
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
            const templateLabels = {
                magic_link: 'Magic Link',
                password_reset: 'Password Reset',
                email_verification: 'Email Verification',
                email_change: 'Email Change',
            };
            const templatePlaceholders = ['link', 'email', 'new_email', 'project_name'];

            const project = Vue.ref(null);
            const config = Vue.ref({});
            const configForm = Vue.reactive({
                provider: 'smtp', from_address: '', from_name: '',
                smtp_host: '', smtp_port: 587, smtp_username: '', smtp_encryption: 'tls', smtp_password: '',
                resend_api_key: '', require_email_confirmation: false,
            });
            const configLoading = Vue.ref(true);
            const testTo = Vue.ref('');
            const sendingTest = Vue.ref(false);

            const templates = Vue.ref([]);
            const templatesLoading = Vue.ref(true);
            const templateModal = Vue.ref(false);
            const templateForm = Vue.reactive({ template_key: '', subject: '', body: '' });
            const preview = Vue.ref(null);

            async function loadProjectHeader() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}`);
                    project.value = body;
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function loadConfig() {
                configLoading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/email-config`);
                    config.value = body;
                    Object.assign(configForm, body, { smtp_password: '', resend_api_key: '' });
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    configLoading.value = false;
                }
            }

            async function saveConfig() {
                if (!configForm.from_address.trim()) { toast.error('From address is required'); return; }
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/email-config`, {
                        method: 'PUT',
                        body: JSON.stringify(configForm),
                    });
                    config.value = body;
                    Object.assign(configForm, body, { smtp_password: '', resend_api_key: '' });
                    toast.success('Email settings saved');
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

            async function loadTemplates() {
                templatesLoading.value = true;
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/templates`);
                    templates.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    templatesLoading.value = false;
                }
            }

            function openTemplate(tpl) {
                templateForm.template_key = tpl.template_key;
                templateForm.subject = tpl.subject;
                templateForm.body = tpl.body;
                preview.value = null;
                templateModal.value = true;
            }

            async function saveTemplate() {
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/auth/templates/${templateForm.template_key}`, {
                        method: 'PUT',
                        body: JSON.stringify({ subject: templateForm.subject, body: templateForm.body }),
                    });
                    toast.success('Template saved');
                    templateModal.value = false;
                    await loadTemplates();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function resetTemplate() {
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/auth/templates/${templateForm.template_key}`, { method: 'DELETE' });
                    toast.success('Template reset to default');
                    templateModal.value = false;
                    await loadTemplates();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            async function previewTemplate() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/auth/templates/preview`, {
                        method: 'POST',
                        body: JSON.stringify({ subject: templateForm.subject, body: templateForm.body }),
                    });
                    preview.value = body;
                } catch (e) {
                    toast.error(e.message);
                }
            }

            loadProjectHeader();
            loadConfig();
            loadTemplates();

            return {
                project, config, configForm, configLoading, testTo, sendingTest,
                templates, templatesLoading, templateModal, templateForm, preview,
                templateLabels, templatePlaceholders,
                saveConfig, sendTest, openTemplate, saveTemplate, resetTemplate, previewTemplate,
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
        'title'     => 'Auth — Loxodontu',
        'pageTitle' => 'Auth',
    ],
];

include t('layouts/dashboard');
