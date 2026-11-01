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

        @php($palette = ($layoutCompany = auth()->user()?->currentCompany()) ? App\Support\Branding\Brand::interfacePalette($layoutCompany) : null)
        @if($palette)
            {{-- The company's own colour under Varumärke replaces NONOX's accent. --}}
            <style>:root { @foreach($palette as $shade => $rgb)--accent-{{ $shade }}: {{ $rgb }}; @endforeach }</style>
        @endif
    </head>
    <body class="font-sans antialiased bg-paper text-gray-900">
        <x-ui.toaster />

        @livewire('navigation-menu')

        @if($layoutCompany)
            <x-spotlight.dialog />
        @endif

        <div class="min-h-screen bg-paper lg:pl-72">
            @if($layoutCompany)
                {{-- Desktop top bar: the spotlight search (ADR-026). On small screens it is an icon in the dark bar. --}}
                <div class="sticky top-0 z-20 hidden items-center border-b border-stone-200/70 bg-paper/90 px-10 py-3 backdrop-blur lg:flex">
                    <x-spotlight.trigger />
                </div>
            @endif
            <x-archived-company-banner :company="$layoutCompany" />

            <!-- Page Heading -->
            @if (isset($header))
                <header class="px-4 pt-6 sm:px-6 lg:px-10 [&_h2]:text-2xl [&_h2]:leading-8 [&_h2]:font-bold [&_h2]:tracking-tight [&_h2]:text-ink">
                    {{ $header }}
                </header>
            @endif

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>

            {{-- For support: which version and build the user is on. --}}
            <footer class="px-4 py-8 text-xs tabular-nums text-gray-500 sm:px-6 lg:px-10">NONOX {{ App\Support\Build\BuildInfo::line() }}</footer>
        </div>

        @stack('modals')

        @livewireScripts
    </body>
</html>
