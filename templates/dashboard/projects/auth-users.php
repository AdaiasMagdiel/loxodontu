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
            <a class="subnav-item active" href="<?= e($projectBase) ?>/auth/users">Users</a>
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/providers">Providers</a>
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/templates">Templates</a>
            <a class="subnav-item" href="<?= e($projectBase) ?>/auth/settings">Settings</a>
        </div>

        <div class="listbar">
            <input v-model="search" class="input" style="max-width:20rem;" placeholder="Search by email&hellip;" />
            <select v-model="roleFilter" class="input" style="max-width:10rem;">
                <option value="">All roles</option>
                <option v-for="r in knownRoles" :key="r" :value="r">{{ r }}</option>
            </select>
        </div>

        <div v-if="loading" class="text-sm" style="color:var(--text-muted);">Loading&hellip;</div>
        <template v-else>
            <div v-if="filteredUsers.length === 0" class="empty-state">
                <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7" /></svg>
                <p class="empty-state-title">No end users yet</p>
                <p class="empty-state-body">End users register themselves through your app's public auth API — there's no invite flow here.</p>
            </div>

            <template v-else>
                <div class="datalist mb-2">
                    <div class="datalist-head">
                        <div class="datalist-cell" style="flex:2;">Email</div>
                        <div class="datalist-cell">Role</div>
                        <div class="datalist-cell">Created</div>
                    </div>
                    <div v-for="u in filteredUsers" :key="u.id" class="datalist-row" style="cursor:pointer;" @click="openUser(u)">
                        <div class="datalist-cell" style="flex:2;">{{ u.email }}</div>
                        <div class="datalist-cell"><span :class="['badge', u.role ? 'badge-accent' : '']">{{ u.role || 'none' }}</span></div>
                        <div class="datalist-cell" style="color:var(--text-muted);">{{ formatDate(u.created_at) }}</div>
                    </div>
                </div>

                <div class="pagination">
                    <span class="pagination-info">{{ users.length ? offset + 1 : 0 }}&ndash;{{ Math.min(offset + limit, total) }} of {{ total }}</span>
                    <div class="flex gap-2">
                        <button class="btn-ghost" :disabled="offset === 0" @click="prevPage">Previous</button>
                        <button class="btn-ghost" :disabled="offset + limit >= total" @click="nextPage">Next</button>
                    </div>
                </div>
            </template>
        </template>

        <!-- USER DRAWER -->
        <template v-if="drawerUser">
            <div class="drawer-backdrop" @click="drawerUser = null"></div>
            <div class="drawer">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-head font-medium">User detail</h3>
                    <button class="btn-ghost btn-icon" @click="drawerUser = null">&times;</button>
                </div>
                <label class="field-label">Email</label>
                <p class="mb-3">{{ drawerUser.email }}</p>
                <label class="field-label">User ID</label>
                <p class="mb-3 font-mono text-sm flex items-center gap-2">
                    {{ drawerUser.id }}
                    <button class="btn-ghost btn-icon" @click="copyToClipboard(String(drawerUser.id), $event.currentTarget)"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg></button>
                </p>
                <label class="field-label">Role</label>
                <select v-model="drawerRole" class="input mt-1 mb-3" style="max-width:12rem;">
                    <option :value="null">none</option>
                    <option v-for="r in knownRoles" :key="r" :value="r">{{ r }}</option>
                    <option v-if="drawerUser.role && !knownRoles.includes(drawerUser.role)" :value="drawerUser.role">{{ drawerUser.role }}</option>
                </select>
                <button class="btn-ghost mb-3" @click="saveRole">Save role</button>
                <label class="field-label">Created</label>
                <p class="mb-3">{{ formatDate(drawerUser.created_at) }}</p>
                <button class="btn-ghost-danger mt-4" @click="removeUser(drawerUser)">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /></svg>
                    Remove user
                </button>
            </div>
        </template>

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
            const knownRoles = ['admin', 'manager'];

            const users = Vue.ref([]);
            const loading = Vue.ref(true);
            const search = Vue.ref('');
            const roleFilter = Vue.ref('');
            const limit = Vue.ref(25);
            const offset = Vue.ref(0);
            const total = Vue.ref(0);

            const drawerUser = Vue.ref(null);
            const drawerRole = Vue.ref(null);

            const confirmState = Vue.reactive({ show: false, message: '', run: () => {} });
            function askConfirm(message, run) {
                confirmState.message = message;
                confirmState.run = () => { confirmState.show = false; run(); };
                confirmState.show = true;
            }

            const filteredUsers = Vue.computed(() => users.value.filter((u) => {
                if (search.value && !u.email.toLowerCase().includes(search.value.toLowerCase())) return false;
                if (roleFilter.value && u.role !== roleFilter.value) return false;
                return true;
            }));

            function formatDate(value) {
                return new Date(value.replace(' ', 'T') + 'Z').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
            }

            async function loadUsers() {
                loading.value = true;
                try {
                    const { body, headers } = await apiFetch(`/projects/${PROJECT_ID}/end-users?limit=${limit.value}&offset=${offset.value}`);
                    users.value = body;
                    total.value = parseInt(headers.get('X-Total-Count') || body.length, 10);
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    loading.value = false;
                }
            }

            function prevPage() { offset.value = Math.max(0, offset.value - limit.value); loadUsers(); }
            function nextPage() { offset.value = offset.value + limit.value; loadUsers(); }

            function openUser(u) {
                drawerUser.value = u;
                drawerRole.value = u.role;
            }

            async function saveRole() {
                try {
                    const { body } = await apiFetch(`/projects/${PROJECT_ID}/end-users/${drawerUser.value.id}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ role: drawerRole.value }),
                    });
                    drawerUser.value.role = body.role;
                    toast.success('Role updated');
                    await loadUsers();
                } catch (e) {
                    toast.error(e.message);
                }
            }

            function removeUser(u) {
                askConfirm(`Remove user "${u.email}"?`, async () => {
                    try {
                        await apiFetch(`/projects/${PROJECT_ID}/end-users/${u.id}`, { method: 'DELETE' });
                        toast.success('User removed');
                        drawerUser.value = null;
                        await loadUsers();
                    } catch (e) {
                        toast.error(e.message);
                    }
                });
            }

            loadUsers();

            return {
                users, loading, search, roleFilter, knownRoles, filteredUsers,
                limit, offset, total, prevPage, nextPage,
                drawerUser, drawerRole, openUser, saveRole, removeUser,
                confirmState, formatDate, copyToClipboard,
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
        'title'     => 'Auth · Users — Loxodontu',
        'pageTitle' => 'Auth',
    ],
];

include t('layouts/dashboard');
