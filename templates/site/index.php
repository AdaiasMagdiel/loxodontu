<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loxodontu | Welcome</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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

    <div class="glass-card max-w-md w-full rounded-3xl p-10 text-center transition-all">
        <div class="inline-flex items-center justify-center w-20 h-20 mb-6 bg-indigo-600 rounded-2xl shadow-lg shadow-indigo-200">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
            </svg>
        </div>

        <h1 class="text-3xl font-bold text-slate-800 mb-3 tracking-tight">
            Welcome to <span class="text-indigo-600">Loxodontu</span>
        </h1>

        <p class="text-slate-500 mb-8 leading-relaxed">
            A lightweight, self-hostable Backend-as-a-Service built with PHP. Start building your API now.
        </p>

        <div class="space-y-3 mb-8">
            <code class="block w-full bg-slate-100 rounded-lg py-3 px-4 text-sm text-slate-600 font-mono border border-slate-200">
                templates/site/index.php
            </code>
            <p class="text-xs text-slate-400 uppercase tracking-widest font-semibold">Edit this file to begin</p>
        </div>

        <a href="/dashboard" class="flex items-center justify-center w-full bg-indigo-600 text-white font-bold py-3 px-6 rounded-xl hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-100 mb-6">
            Go to Dashboard
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
            </svg>
        </a>

        <hr class="my-8 border-slate-200">

        <div class="flex justify-center gap-6">
            <a href="https://github.com/adaiasmagdiel/loxodontu" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">Source</a>
        </div>
    </div>

</body>

</html>