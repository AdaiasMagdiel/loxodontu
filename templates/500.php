<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 | Server Error</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: radial-gradient(circle at top right, #fff1f2, #f1f5f9);
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
    <div class="glass-card max-w-md w-full rounded-3xl p-10 text-center border-t-4 border-rose-500">
        <div class="inline-flex items-center justify-center w-20 h-20 mb-6 bg-rose-100 rounded-2xl shadow-lg shadow-rose-50">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>

        <h1 class="text-6xl font-bold text-slate-800 mb-2">500</h1>
        <h2 class="text-xl font-semibold text-slate-700 mb-4">Server Error</h2>

        <p class="text-slate-500 mb-8 leading-relaxed">
            Something went wrong on our end. Please try again in a moment.
        </p>

        <div class="space-y-3">
            <button onclick="window.location.reload()" class="w-full bg-slate-800 text-white font-bold py-3 px-6 rounded-xl hover:bg-slate-900 transition-all">
                Try Again
            </button>
            <a href="/" class="block text-sm font-medium text-slate-500 hover:text-indigo-600 transition-colors">
                Return Home
            </a>
        </div>

        <hr class="my-8 border-slate-200">
        <p class="text-xs text-slate-400 uppercase tracking-widest font-semibold">Check server logs for details</p>
    </div>
</body>

</html>