@props(['title' => __('Min sida')])
@php
    $company = auth()->user()?->currentCompany();
    // The company's own brand, or the accounting firm's when the company shows it (ADR-030).
    $presented = $company ? App\Support\Branding\FirmBranding::forViewer(auth()->user(), $company) : null;
    $brand = $presented?->brand();
    $palette = $presented?->palette();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} · {{ auth()->user()?->currentCompany()?->name }}</title>
        @include('partials.favicon', ['manifest' => 'anstalld.webmanifest'])
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        @if($palette)
            <style>:root { @foreach($palette as $shade => $rgb)--accent-{{ $shade }}: {{ $rgb }}; @endforeach }</style>
        @endif
    </head>
    <body class="font-sans antialiased">
        <x-ui.toaster />

        <div class="min-h-screen bg-paper">
            <header class="border-b border-gray-200 bg-white" style="border-top: 4px solid {{ $brand?->color ?? App\Support\Branding\Brand::DEFAULT_COLOR }}">
                <div class="mx-auto flex max-w-2xl items-center justify-between gap-4 px-4 py-4">
                    <div class="flex min-w-0 items-center gap-3">
                        @if($presented?->logoUrl())
                            <img src="{{ $presented->logoUrl() }}" alt="{{ $presented->firmName ?? $company->name }}" class="h-8 max-w-28 object-contain sm:h-10 sm:max-w-36">
                        @endif
                        <div class="min-w-0">
                            <p class="truncate text-xs font-medium uppercase tracking-wide" style="color: {{ $brand?->accentText() ?? '#616e7c' }}">{{ $company?->name }}</p>
                            <p class="text-lg font-semibold text-gray-900">{{ __('Min sida') }}</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900">{{ __('Logga ut') }}</button>
                    </form>
                </div>
                @if($presented?->viaFirm())
                    {{-- The accounting firm the employer works with (ADR-030). --}}
                    <p class="mx-auto -mt-2 max-w-2xl px-4 pb-3 text-xs text-gray-600" data-firm-brand>{{ $presented->title() }}</p>
                @endif
            </header>

            <main class="mx-auto max-w-2xl space-y-6 px-4 py-6 sm:py-10">
                {{ $slot }}
            </main>

            <footer class="mx-auto flex max-w-2xl items-center justify-center gap-2 px-4 pb-8 text-xs text-gray-500">
                <span>{{ __('Drivs med') }}</span>
                <img src="{{ asset('brand/nonox.svg') }}" alt="NONOX" class="h-4 w-auto">
            </footer>
        </div>

        @livewireScripts
    </body>
</html>
