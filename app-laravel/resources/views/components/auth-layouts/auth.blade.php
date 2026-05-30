@props([
    'title' => config('app.name'),
    'heading' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
    <main class="mx-auto flex min-h-screen w-full max-w-md items-center px-4 py-10">
        <section class="w-full rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
            @if (filled($heading))
                <h1 class="mb-6 text-2xl font-semibold tracking-tight">{{ $heading }}</h1>
            @endif

            {{ $slot }}
        </section>
    </main>
</body>
</html>
