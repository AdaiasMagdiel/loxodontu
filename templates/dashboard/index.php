<?php
ob_start();
?>
<template id="tpl-page">
    <main class="px-8 py-8 max-w-5xl mx-auto">

        <!-- LOGIN / REGISTER -->
        <div v-if="!store.auth" class="min-h-screen -mt-8 -mx-8 flex items-center justify-center px-4">
            <div class="w-full max-w-sm rounded-lg p-8" style="background:var(--bg-surface); border:1px solid var(--border);">
                <div class="flex items-center gap-2 mb-6">
                    <span class="w-2 h-2 rounded-full" style="background:var(--accent); box-shadow:0 0 8px var(--accent);"></span>
                    <span class="font-head font-bold uppercase tracking-wider" style="color:var(--text-main);">Loxodontu</span>
                </div>

                <div class="flex mb-6 rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    <button class="tab-link flex-1 rounded-none" :class="{ active: authForm.mode === 'login' }"
                        @click="authForm.mode = 'login'; authForm.error = ''">Log in</button>
                    <button class="tab-link flex-1 rounded-none" :class="{ active: authForm.mode === 'register' }"
                        @click="authForm.mode = 'register'; authForm.error = ''">Register</button>
                </div>

                <form @submit.prevent="submitAuth" class="flex flex-col gap-3">
                    <label v-if="authForm.mode === 'register'" class="field-label">
                        Name
                        <input v-model="authForm.name" type="text" required class="input mt-1" />
                    </label>
                    <label class="field-label">
                        Email
                        <input v-model="authForm.email" type="email" required class="input mt-1" />
                    </label>
                    <label class="field-label">
                        Password
                        <input v-model="authForm.password" type="password" required minlength="8" class="input mt-1" />
                    </label>

                    <p v-if="authForm.error" class="text-xs" style="color:#F85149;">{{ authForm.error }}</p>

                    <button type="submit" class="btn-accent justify-center mt-2" :disabled="authForm.loading">
                        {{ authForm.loading ? 'Please wait…' : (authForm.mode === 'login' ? 'Log in' : 'Create account') }}
                    </button>
                </form>
            </div>
        </div>

        <!-- HOME -->
        <div v-else>
            <div class="mb-8">
                <p class="font-head font-medium text-xs uppercase tracking-widest mb-2" style="color:var(--accent);">Dashboard</p>
                <h1 class="font-head font-bold text-3xl uppercase tracking-wide" style="color:var(--text-main); line-height:1.1;">
                    Welcome, {{ store.auth.user.name }}
                </h1>
                <p class="text-sm mt-1" style="color:var(--text-muted);">{{ store.auth.user.email }}</p>
            </div>

            <div class="flex items-center justify-between mb-4">
                <p class="font-head font-medium text-xs uppercase tracking-widest" style="color:var(--text-muted);">Your Projects</p>
                <a href="/dashboard/projects" class="btn-accent">+ New Project</a>
            </div>

            <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading…</div>
            <div v-else-if="projects.length === 0" class="rounded-lg p-8 text-center text-sm" style="background:var(--bg-surface); border:1px solid var(--border); color:var(--text-muted);">
                No projects yet. <a href="/dashboard/projects" style="color:var(--accent);">Create your first one</a>.
            </div>
            <div v-else class="rounded-lg overflow-hidden" style="border:1px solid var(--border);">
                <a v-for="p in projects.slice(0, 5)" :key="p.id" :href="'/dashboard/projects/' + p.id"
                    class="flex items-center justify-between px-5 py-3 no-underline"
                    style="border-bottom:1px solid var(--border); background:var(--bg-surface);">
                    <div>
                        <p class="font-head font-medium text-sm" style="color:var(--text-main);">{{ p.name }}</p>
                        <p class="text-xs font-mono" style="color:var(--text-muted);">{{ p.slug }}</p>
                    </div>
                    <span class="text-xs" style="color:var(--text-muted);">{{ formatDate(p.created_at) }}</span>
                </a>
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
            const authForm = Vue.reactive({ mode: 'login', name: '', email: '', password: '', loading: false, error: '' });
            const projects = Vue.ref([]);
            const loading = Vue.ref(false);

            function formatDate(v) {
                if (!v) return '—';
                return new Date(v.replace(' ', 'T')).toLocaleDateString();
            }

            async function submitAuth() {
                authForm.loading = true;
                authForm.error = '';
                try {
                    const path = authForm.mode === 'login' ? '/auth/login' : '/auth/register';
                    const payload = authForm.mode === 'login'
                        ? { email: authForm.email, password: authForm.password }
                        : { name: authForm.name, email: authForm.email, password: authForm.password };
                    const { body } = await apiFetch(path, { method: 'POST', body: JSON.stringify(payload) });
                    localStorage.setItem('loxo-auth', JSON.stringify(body));
                    location.reload();
                } catch (e) {
                    authForm.error = e.message;
                } finally {
                    authForm.loading = false;
                }
            }

            async function loadProjects() {
                loading.value = true;
                try {
                    const { body } = await apiFetch('/projects');
                    projects.value = body;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            if (store.auth) loadProjects();

            return { store, authForm, submitAuth, projects, loading, formatDate };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body'   => $body,
    'script' => $script,
    'info'   => [
        'title'     => 'Home — Loxodontu',
        'pageTitle' => 'Home',
    ],
];

include t('layouts/dashboard');
