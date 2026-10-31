<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'NONOX Ekonomisystem') }}</title>

        @include('partials.favicon')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles
    </head>
    <body>
        <div class="font-sans text-gray-900 antialiased">
            {{ $slot }}
        </div>

        <p class="pb-6 text-center text-xs tabular-nums text-gray-500">NONOX {{ App\Support\Build\BuildInfo::line() }}</p>

        @livewireScripts
        @include('start.partials.cookie-banner')
</body>
</html>
