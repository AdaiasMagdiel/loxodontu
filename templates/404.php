<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 | Page Not Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: radial-gradient(circle at top right, #f8fafc, #e2e8f0);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-6">
    <div class="glass-card max-w-md w-full rounded-3xl p-10 text-center">
        <div class="inline-flex items-center justify-center w-20 h-20 mb-6 bg-amber-100 rounded-2xl shadow-lg shadow-amber-50">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>

        <h1 class="text-6xl font-bold text-slate-800 mb-2">404</h1>
        <h2 class="text-xl font-semibold text-slate-700 mb-4">Page Not Found</h2>

        <p class="text-slate-500 mb-8 leading-relaxed">
            The page you are looking for doesn't exist or was moved.
        </p>

        <a href="/" class="inline-block w-full bg-indigo-600 text-white font-bold py-3 px-6 rounded-xl hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100">
            Back Home
        </a>

        <hr class="my-8 border-slate-200">
        <p class="text-xs text-slate-400 uppercase tracking-widest font-semibold">Loxodontu</p>
    </div>
</body>

</html>