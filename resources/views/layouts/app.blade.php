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

        <div class="min-h-screen bg-paper lg:pl-72">

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        @stack('modals')

        @livewireScripts
    </body>
</html>
