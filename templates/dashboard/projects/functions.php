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

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_20rem] gap-5">
            <section>
                <div class="flex items-center justify-between mb-4">
                    <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">Functions</p>
                    <button class="btn-accent" @click="openCreate">New Function</button>
                </div>

                <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading...</div>
                <div v-else-if="functions.length === 0" class="rounded-lg p-8 text-center text-sm" style="background:var(--bg-surface); border:1px solid var(--border); color:var(--text-muted);">
                    No functions registered yet.
                </div>
                <div v-else class="rounded-lg overflow-hidden" style="border:1px solid var(--border);">
                    <div v-for="fn in functions" :key="fn.id" class="px-5 py-4" style="background:var(--bg-surface); border-bottom:1px solid var(--border);">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <p class="font-head font-medium text-sm" style="color:var(--text-main);">{{ fn.name }}</p>
                                    <span class="text-[10px] font-mono px-2 py-0.5 rounded" style="background:var(--bg-hover); color:var(--text-muted);">{{ fn.enabled ? 'enabled' : 'disabled' }}</span>
                                </div>
                                <p class="text-xs font-mono mt-1" style="color:var(--text-muted);">/api/v1/{{ PROJECT_ID }}/functions/{{ fn.slug }}</p>
                                <p class="text-xs font-mono mt-1" style="color:var(--text-muted);">{{ fn.handler }}</p>
                                <p v-if="fn.description" class="text-xs mt-2" style="color:var(--text-muted);">{{ fn.description }}</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button class="btn-ghost" @click="editFunction(fn)">Edit</button>
                                <button class="btn-ghost-danger" @click="deleteFunction(fn)">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <aside class="rounded-lg p-4 h-fit" style="background:var(--bg-surface); border:1px solid var(--border);">
                <p class="font-head font-medium text-xs uppercase tracking-widest mb-3" style="color:var(--text-muted);">Invocation</p>
                <label class="field-label">Function
                    <select v-model="test.slug" class="input mt-1">
                        <option value="">Select...</option>
                        <option v-for="fn in functions" :key="fn.id" :value="fn.slug">{{ fn.slug }}</option>
                    </select>
                </label>
                <label class="field-label mt-3">Payload
                    <textarea v-model="test.payload" class="input font-mono mt-1 min-h-[120px]" spellcheck="false"></textarea>
                </label>
                <button class="btn-accent w-full justify-center mt-3" @click="invokeFunction" :disabled="test.running">{{ test.running ? 'Running' : 'Invoke' }}</button>
                <pre v-if="test.output" class="text-xs font-mono whitespace-pre-wrap mt-3 rounded-md p-3 overflow-auto max-h-64" style="background:var(--bg-hover); color:var(--text-main);">{{ test.output }}</pre>
            </aside>
        </div>

        <div v-if="modal" class="modal-backdrop" @click.self="modal = false">
            <div class="modal-card max-w-xl">
                <p class="modal-title">{{ form.id ? 'Edit Function' : 'New Function' }}</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="field-label">Name<input v-model="form.name" class="input mt-1" /></label>
                    <label class="field-label">Slug<input v-model="form.slug" class="input mt-1 font-mono" /></label>
                </div>
                <label class="field-label mt-3">Handler<input v-model="form.handler" class="input mt-1 font-mono" placeholder="App\Jobs\DailyCleanup::handle" /></label>
                <label class="field-label mt-3">Description<textarea v-model="form.description" class="input mt-1 min-h-[72px]"></textarea></label>
                <p class="field-label mt-3 mb-1">Methods</p>
                <div class="flex gap-3 flex-wrap">
                    <label v-for="method in methodOptions" :key="method" class="flex items-center gap-1 text-xs" style="color:var(--text-main);">
                        <input type="checkbox" :value="method" v-model="form.methods" /> {{ method }}
                    </label>
                </div>
                <div class="flex gap-4 mt-3">
                    <label class="flex items-center gap-2 text-xs" style="color:var(--text-main);"><input type="checkbox" v-model="form.require_api_key" /> Require API key</label>
                    <label class="flex items-center gap-2 text-xs" style="color:var(--text-main);"><input type="checkbox" v-model="form.enabled" /> Enabled</label>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn-ghost" @click="modal = false">Cancel</button>
                    <button class="btn-accent" @click="saveFunction">Save</button>
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

            const PROJECT_ID = <?= (int) $projectId ?>;
            const methodOptions = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
            const project = Vue.ref(null);
            const functions = Vue.ref([]);
            const loading = Vue.ref(true);
            const modal = Vue.ref(false);
            const form = Vue.reactive({ id: null, name: '', slug: '', handler: '', description: '', methods: ['POST'], require_api_key: true, enabled: true });
            const test = Vue.reactive({ slug: '', payload: '{}', output: '', running: false });

            async function loadProjectHeader() {
                try { project.value = (await apiFetch(`/projects/${PROJECT_ID}`)).body; } catch (e) { toast.error(e.message); }
            }

            async function loadFunctions() {
                loading.value = true;
                try { functions.value = (await apiFetch(`/projects/${PROJECT_ID}/functions`)).body; } catch (e) { toast.error(e.message); } finally { loading.value = false; }
            }

            function resetForm() {
                Object.assign(form, { id: null, name: '', slug: '', handler: '', description: '', methods: ['POST'], require_api_key: true, enabled: true });
            }

            function openCreate() { resetForm(); modal.value = true; }

            function editFunction(fn) {
                Object.assign(form, {
                    id: fn.id,
                    name: fn.name,
                    slug: fn.slug,
                    handler: fn.handler,
                    description: fn.description || '',
                    methods: fn.methods && fn.methods.length ? fn.methods : [],
                    require_api_key: fn.require_api_key,
                    enabled: fn.enabled,
                });
                modal.value = true;
            }

            async function saveFunction() {
                if (!form.name.trim() || !form.slug.trim() || !form.handler.trim()) { toast.error('Name, slug, and handler are required'); return; }
                const payload = { name: form.name, slug: form.slug, handler: form.handler, description: form.description, methods: form.methods, require_api_key: form.require_api_key, enabled: form.enabled };
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/functions${form.id ? '/' + form.id : ''}`, {
                        method: form.id ? 'PATCH' : 'POST',
                        body: JSON.stringify(payload),
                    });
                    modal.value = false;
                    toast.success('Function saved');
                    await loadFunctions();
                } catch (e) { toast.error(e.message); }
            }

            async function deleteFunction(fn) {
                if (!confirm(`Delete function "${fn.name}"?`)) return;
                try {
                    await apiFetch(`/projects/${PROJECT_ID}/functions/${fn.id}`, { method: 'DELETE' });
                    toast.success('Function deleted');
                    await loadFunctions();
                } catch (e) { toast.error(e.message); }
            }

            async function invokeFunction() {
                if (!test.slug) { toast.error('Select a function'); return; }
                let payload = {};
                try { payload = test.payload.trim() ? JSON.parse(test.payload) : {}; } catch (e) { toast.error('Payload must be valid JSON'); return; }
                test.running = true;
                test.output = '';
                try {
                    const { body } = await apiFetch(`/${PROJECT_ID}/functions/${test.slug}`, {
                        method: 'POST',
                        body: JSON.stringify(payload),
                    });
                    test.output = JSON.stringify(body, null, 2);
                } catch (e) { test.output = e.message; toast.error(e.message); } finally { test.running = false; }
            }

            loadProjectHeader();
            loadFunctions();

            return { PROJECT_ID, project, functions, loading, modal, form, methodOptions, test, openCreate, editFunction, saveFunction, deleteFunction, invokeFunction };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Functions - Loxodontu',
        'pageTitle' => 'Functions',
    ],
];

include t('layouts/dashboard');
