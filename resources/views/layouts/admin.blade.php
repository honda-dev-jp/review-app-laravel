<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@if (isset($title) && $title->isNotEmpty()){{ $title }} | @endif映画レビューアプリ 管理</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.admin-navigation')

            <main class="min-h-[calc(100vh-3.5rem)] md:pl-48">
                <div class="max-w-7xl px-4 sm:px-6">
                    @if (isset($header))
                        <header class="pt-6">
                            {{ $header }}
                        </header>
                    @endif

                    <div class="pt-5 pb-8">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
