<?php
ob_start();
?>
<template id="tpl-page">
    <main class="p-8">
        <div class="max-w-md mx-auto bg-white rounded-xl shadow-md overflow-hidden md:max-w-2xl p-6">
            <h1 class="text-2xl font-bold text-indigo-600">Welcome to Loxodontu</h1>
            <p class="mt-2 text-slate-500 italic">"A lightweight, self-hostable BaaS built with PHP."</p>

            <div class="mt-6 p-4 bg-indigo-50 rounded-lg">
                <p class="text-sm font-medium text-indigo-900">User: {{ store.user.name }}</p>
                <p class="text-xs text-indigo-700">Role: {{ store.user.role }}</p>
            </div>

            <div class="flex gap-2">
                <button @click="count++" class="mt-6 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    Vue Reactivity: {{ count }}
                </button>

                <button @click="showToast()" class="mt-6 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    Toast example
                </button>
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

            const count = Vue.ref(0);

            const showToast = () => {
                toast.success('Hello, World!');
            }

            return {
                store,

                count,

                showToast
            };
        }
    });
</script>
<?php $script = ob_get_clean(); ?>

<?php
$page = [
    'body' => $body,
    'script' => $script,
    'info' => [
        'title' => 'Home - Loxodontu'
    ]
];

include t('layouts/dashboard');
