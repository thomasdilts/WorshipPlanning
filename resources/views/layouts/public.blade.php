<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600|instrument-serif:400" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="wp-public min-h-screen bg-[var(--color-wp-chrome)] text-[var(--color-wp-text)] antialiased">
    <header class="border-b border-[var(--color-wp-border)] bg-[var(--color-wp-ground)]">
        <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-4 sm:px-6">
            <a href="{{ url('/admin/login') }}" class="text-sm font-semibold tracking-tight text-[var(--color-wp-accent)] hover:text-[var(--color-wp-accent-deep)]">
                {{ config('app.name', 'WorshipPlanning') }}
            </a>
            <a href="{{ url('/admin/login') }}" class="text-sm font-medium text-[var(--color-wp-muted)] underline-offset-4 hover:text-[var(--color-wp-accent)] hover:underline">
                {{ __('Sign in') }}
            </a>
        </div>
    </header>

    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
        @yield('content')
    </main>
</body>
</html>
