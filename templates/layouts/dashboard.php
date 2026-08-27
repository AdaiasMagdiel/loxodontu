<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page['info']['title'] ?? 'Loxodontu') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/<?= isDev() ? 'vue.global.js' : 'vue.global.prod.js' ?>"></script>
</head>

<body class="bg-slate-50 text-slate-900">
    <div id="app" class="min-h-screen flex flex-col">
        <page></page>
    </div>

    <?= $page['body'] ?? '' ?>

    <script type="module">
        import toast from 'https://cdn.jsdelivr.net/npm/sonner-js/+esm';
        window.toast = toast;
    </script>

    <script>
        const store = Vue.reactive({
            user: {
                'name': 'Adaías Magdiel',
                'role': 'Developer'
            }
        });

        window.__APP = Vue.createApp({
            setup() {
                return {};
            }
        });

        __APP.provide('store', store);
    </script>

    <?= $page['script'] ?? '' ?>

    <script>
        __APP.mount('#app');
    </script>
</body>

</html>