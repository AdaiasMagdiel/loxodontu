<?php
ob_start();
?>
<template id="tpl-page">
    <main class="px-8 py-8 max-w-2xl mx-auto">
        <h1 class="font-head font-bold text-2xl uppercase tracking-wide mb-6" style="color:var(--text-main);">Account</h1>

        <!-- Profile -->
        <section class="rounded-lg p-6 mb-6" style="background:var(--bg-surface); border:1px solid var(--border);">
            <p class="font-head font-medium text-xs uppercase tracking-widest mb-4" style="color:var(--text-muted);">Profile</p>
            <div class="flex flex-col gap-3">
                <label class="field-label">Name
                    <input v-model="profile.name" class="input mt-1" placeholder="Your name" />
                </label>
                <label class="field-label">Email
                    <input v-model="profile.email" type="email" class="input mt-1" placeholder="your@email.com" />
                </label>
                <div class="flex justify-end">
                    <button class="btn-accent" :disabled="profileSaving" @click="saveProfile">
                        {{ profileSaving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </section>

        <!-- Change Password -->
        <section class="rounded-lg p-6 mb-6" style="background:var(--bg-surface); border:1px solid var(--border);">
            <p class="font-head font-medium text-xs uppercase tracking-widest mb-4" style="color:var(--text-muted);">Change Password</p>
            <div class="flex flex-col gap-3">
                <label class="field-label">Current password
                    <input v-model="pwd.current" type="password" class="input mt-1" />
                </label>
                <label class="field-label">New password
                    <input v-model="pwd.next" type="password" class="input mt-1" placeholder="Min. 8 characters" />
                </label>
                <label class="field-label">Confirm new password
                    <input v-model="pwd.confirm" type="password" class="input mt-1" />
                </label>
                <div class="flex justify-end">
                    <button class="btn-accent" :disabled="pwdSaving" @click="savePassword">
                        {{ pwdSaving ? 'Saving…' : 'Update Password' }}
                    </button>
                </div>
            </div>
        </section>

        <!-- Danger Zone -->
        <section class="rounded-lg p-6" style="background:var(--bg-surface); border:1px solid #F85149;">
            <p class="font-head font-medium text-xs uppercase tracking-widest mb-1" style="color:#F85149;">Danger Zone</p>
            <p class="text-xs mb-4" style="color:var(--text-muted);">Deleting your account is permanent and cannot be undone. All your projects and data will be destroyed.</p>
            <button class="btn-ghost-danger" @click="openDeleteModal">Delete Account</button>
        </section>

        <!-- Delete Account Modal -->
        <div v-if="deleteModal" class="modal-backdrop" @click.self="deleteModal = false">
            <div class="modal-card max-w-sm">
                <p class="modal-title" style="color:#F85149;">Delete Account</p>
                <p class="text-xs mb-4" style="color:var(--text-muted);">Enter your password to confirm. This action is permanent.</p>
                <label class="field-label">Password
                    <input v-model="deletePassword" type="password" class="input mt-1" />
                </label>
                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn-ghost" @click="deleteModal = false">Cancel</button>
                    <button class="btn-ghost-danger" :disabled="deleting" @click="confirmDelete">
                        {{ deleting ? 'Deleting…' : 'Delete My Account' }}
                    </button>
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

            const profile = Vue.reactive({
                name:  store.auth.user.name  || '',
                email: store.auth.user.email || '',
            });
            const profileSaving = Vue.ref(false);

            const pwd = Vue.reactive({ current: '', next: '', confirm: '' });
            const pwdSaving = Vue.ref(false);

            const deleteModal    = Vue.ref(false);
            const deletePassword = Vue.ref('');
            const deleting       = Vue.ref(false);

            async function saveProfile() {
                profileSaving.value = true;
                try {
                    const body = {};
                    if (profile.name  !== store.auth.user.name)  body.name  = profile.name;
                    if (profile.email !== store.auth.user.email) body.email = profile.email;
                    if (Object.keys(body).length === 0) { toast.info('No changes to save'); return; }

                    const { body: updated } = await apiFetch('/auth/me', {
                        method: 'PATCH',
                        body: JSON.stringify(body),
                    });
                    store.auth.user = { ...store.auth.user, ...updated };
                    localStorage.setItem('loxo-auth', JSON.stringify(store.auth));
                    toast.success('Profile updated');
                    document.getElementById('user-name-chip').textContent = updated.name || updated.email;
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    profileSaving.value = false;
                }
            }

            async function savePassword() {
                if (!pwd.current) { toast.error('Current password is required'); return; }
                if (pwd.next.length < 8) { toast.error('New password must be at least 8 characters'); return; }
                if (pwd.next !== pwd.confirm) { toast.error('Passwords do not match'); return; }
                pwdSaving.value = true;
                try {
                    await apiFetch('/auth/me', {
                        method: 'PATCH',
                        body: JSON.stringify({ current_password: pwd.current, password: pwd.next }),
                    });
                    pwd.current = '';
                    pwd.next    = '';
                    pwd.confirm = '';
                    toast.success('Password updated');
                } catch (e) {
                    toast.error(e.message);
                } finally {
                    pwdSaving.value = false;
                }
            }

            function openDeleteModal() {
                deletePassword.value = '';
                deleteModal.value = true;
            }

            async function confirmDelete() {
                if (!deletePassword.value) { toast.error('Password is required'); return; }
                deleting.value = true;
                try {
                    await apiFetch('/auth/me', {
                        method: 'DELETE',
                        body: JSON.stringify({ password: deletePassword.value }),
                    });
                    localStorage.removeItem('loxo-auth');
                    location.href = '/dashboard';
                } catch (e) {
                    toast.error(e.message);
                    deleting.value = false;
                }
            }

            return {
                profile, profileSaving, saveProfile,
                pwd, pwdSaving, savePassword,
                deleteModal, deletePassword, deleting, openDeleteModal, confirmDelete,
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
        'title'     => 'Account — Loxodontu',
        'pageTitle' => 'Account',
    ],
];

include t('layouts/dashboard');
