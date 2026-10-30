@props(['title' => __('Min sida')])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} · {{ auth()->user()?->currentCompany()?->name }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <x-ui.toaster />

        <div class="min-h-screen bg-paper">
            <header class="border-b border-gray-200 bg-white">
                <div class="mx-auto flex max-w-2xl items-center justify-between gap-4 px-4 py-4">
                    <div class="min-w-0">
                        <p class="truncate text-xs font-medium uppercase tracking-wide text-gray-500">{{ auth()->user()?->currentCompany()?->name }}</p>
                        <p class="text-lg font-semibold text-gray-900">{{ __('Min sida') }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900">{{ __('Logga ut') }}</button>
                    </form>
                </div>
            </header>

            <main class="mx-auto max-w-2xl space-y-6 px-4 py-6 sm:py-10">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
