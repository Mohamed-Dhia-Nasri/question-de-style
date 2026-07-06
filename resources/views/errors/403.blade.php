<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 | Question de Style</title>
    @vite(['resources/css/app.css'])
    <script>
        (function () {
            const savedTheme = localStorage.getItem('theme');
            const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            if ((savedTheme || systemTheme) === 'dark') document.documentElement.classList.add('dark');
        })();
    </script>
</head>

<body class="bg-white dark:bg-gray-900">
    <div class="flex min-h-screen flex-col items-center justify-center p-6 text-center">
        <p class="mb-2 text-title-md font-bold text-gray-800 dark:text-white/90">403</p>
        <h1 class="mb-2 text-lg font-semibold text-gray-800 dark:text-white/90">You don't have access to this area</h1>
        <p class="mb-6 max-w-md text-sm text-gray-500 dark:text-gray-400">
            Your role doesn't include this permission. If you believe it should, contact an administrator.
        </p>
        <a href="{{ url('/') }}"
            class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-5 py-3 text-sm font-medium text-white hover:bg-brand-600">
            Back to home
        </a>
    </div>
</body>

</html>
