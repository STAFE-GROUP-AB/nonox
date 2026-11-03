<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $layoutCompany = auth()->user()?->currentCompany();
            // Every page is named after its own heading (WCAG 2.4.2); a page may pass its own title.
            $pageTitle = isset($title) && trim((string) $title) !== ''
                ? (string) $title
                : App\Support\Accessibility\PageTitle::heading((string) ($header ?? ''), (string) $slot);
        @endphp
        <title>{{ App\Support\Accessibility\PageTitle::compose($pageTitle, $layoutCompany?->name) }}</title>

        @include('partials.favicon')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        @php
            // The client side of a company linked to an accounting firm sees the firm's colours (ADR-030).
            $layoutBrand = $layoutCompany ? App\Support\Branding\FirmBranding::forViewer(auth()->user(), $layoutCompany) : null;
            $palette = $layoutBrand?->palette();
        @endphp
        @if($palette)
            {{-- The company's own colour under Varumärke, or its firm's, replaces NONOX's accent. --}}
            <style>:root { @foreach($palette as $shade => $rgb)--accent-{{ $shade }}: {{ $rgb }}; @endforeach }</style>
        @endif
    </head>
    <body class="font-sans antialiased bg-paper text-gray-900">
        <a href="#main" class="nx-skip-link">{{ __('Hoppa till innehållet') }}</a>
        <x-ui.toaster />

        @livewire('navigation-menu')

        @if($layoutCompany)
            <x-spotlight.dialog />
        @endif

        <div class="min-h-screen bg-paper lg:pl-72">
            @if($layoutCompany)
                {{-- Desktop top bar: the spotlight search (ADR-026). On small screens it is an icon in the dark bar. --}}
                <div data-sticky-bar class="sticky top-0 z-20 hidden items-center border-b border-stone-200/70 bg-paper/90 px-10 py-3 backdrop-blur lg:flex">
                    <x-spotlight.trigger />
                </div>
            @endif
            <x-archived-company-banner :company="$layoutCompany" />
            <x-test-company-banner :company="$layoutCompany" />

            {{-- The skip link lands here: the page heading and the page. --}}
            <main id="main" tabindex="-1" class="focus:outline-hidden">
            @if (isset($header))
                <header class="px-4 pt-6 sm:px-6 lg:px-10 [&_h1]:text-2xl [&_h1]:leading-8 [&_h1]:font-bold [&_h1]:tracking-tight [&_h1]:text-ink">
                    {{ $header }}
                </header>
            @endif

                {{ $slot }}
            </main>

            {{-- For support: which version and build the user is on. --}}
            <footer class="px-4 py-8 text-xs tabular-nums text-gray-500 sm:px-6 lg:px-10">NONOX {{ App\Support\Build\BuildInfo::line() }}</footer>
        </div>

        @stack('modals')

        {{-- The encrypted chat (ADR-045): any signed-in user, any page. --}}
        @auth
            <x-secure-chat.panel :company="$layoutCompany" />
        @endauth

        {{-- Read aloud by screen readers: what happened after a form was sent (resources/js/accessibility.js). --}}
        <div id="nx-announcer" class="sr-only" role="status" aria-live="polite" aria-atomic="true"
             data-one-error="{{ __('Formuläret har ett fel. Rätta det och skicka igen.') }}"
             data-many-errors="{{ __('Formuläret har :count fel. Rätta dem och skicka igen.') }}"></div>

        @livewireScripts
    </body>
</html>
