<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-100 dark:bg-gray-950 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ config('app.name') }}
            </h1>
            @isset($heading)
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $heading }}</p>
            @endisset
        </div>

        <div class="rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 space-y-5">
            {{ $slot }}
        </div>
    </div>
</body>
</html>
