<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center bg-gray-50 px-4 py-8">
            <div>
                <a href="/">
                    <img src="{{ asset('images/logo-header.png') }}" alt="映画レビュー" class="w-48 sm:w-56 h-auto">
                </a>
            </div>

            <div class="w-full max-w-lg mt-6 rounded-2xl border border-slate-200 bg-white px-7 py-7 shadow-sm sm:px-8">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
