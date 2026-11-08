@php
    /*
     * The customer portal's frame (ADR-075 §9): the company's own brand
     * (logo, colour made readable at 4.5:1), never the accounting firm's; the
     * neutral NONOX look before the visitor's company is known. No inline
     * script: portal.js only.
     */
    $context = app(App\Modules\Portal\Support\PortalContext::class);
    $settings ??= $context->has() ? $context->settings() : null;
    $company ??= $context->has() ? $context->company() : ($settings ? App\Models\Company::query()->find($settings->company_id) : null);
    $brand = $company ? App\Support\Branding\Brand::for($company) : null;
    $color = $brand ? App\Support\Branding\Brand::readable($brand->color) : '#15171C';
    $companyName = $company ? ($company->legal_name ?: $company->name) : null;
    $signedIn = Auth::guard('portal')->check();
    $toasts = App\Support\Toast::pending();
    $nav = $context->has() ? [
        ['portal.home', __('Översikt')],
        ['portal.invoices', __('Fakturor')],
        ['portal.statement', __('Kontoutdrag')],
        ['portal.quotes', __('Offerter och ordrar')],
        ['portal.questions', __('Frågor')],
    ] : [];
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? __('Kundportal') }}{{ $companyName ? ' – '.$companyName : '' }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/nonox-icon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/portal.js'])
</head>
<body class="bg-stone-100 font-sans text-gray-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-10 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">{{ __('Hoppa till innehållet') }}</a>
    <div class="h-1.5" style="background: {{ $color }}"></div>
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-4">
            <div class="flex min-w-0 items-center gap-3">
                @if($brand && ($logo = $brand->logoDataUri()))
                    <img src="{{ $logo }}" alt="{{ $companyName }}" class="max-h-10 max-w-40">
                @endif
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold">{{ $companyName ?? __('Kundportal') }}</p>
                    @if($context->has())
                        <p class="truncate text-xs text-gray-600">{{ __('Kundportal för :customer', ['customer' => $context->customer()->name]) }}</p>
                    @endif
                </div>
            </div>
            @if($signedIn)
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <a href="{{ route('portal.mine') }}" class="font-medium underline underline-offset-2">{{ __('Mina uppgifter') }}</a>
                    <form method="POST" action="{{ route('portal.logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg px-3 py-2 font-medium ring-1 ring-stone-300 hover:bg-stone-50">{{ __('Logga ut') }}</button>
                    </form>
                </div>
            @endif
        </div>
        @if($nav !== [])
            <nav aria-label="{{ __('Kundportalen') }}" class="mx-auto max-w-5xl overflow-x-auto px-4">
                <ul class="flex gap-1 text-sm">
                    @foreach($nav as [$route, $label])
                        @php($active = request()->routeIs($route) || request()->routeIs($route.'.*'))
                        <li>
                            <a href="{{ route($route, ['portalKey' => $context->key()]) }}" @if($active) aria-current="page" @endif
                               class="block whitespace-nowrap border-b-2 px-3 py-3 font-medium {{ $active ? 'text-gray-900' : 'border-transparent text-gray-600 hover:text-gray-900' }}" @if($active) style="border-color: {{ $color }}" @endif>{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </header>

    <main id="main" class="mx-auto max-w-5xl px-4 pb-16 pt-6 sm:pt-10">
        <div aria-live="polite" role="status">
            @foreach($toasts as $toast)
                <p class="mb-4 rounded-lg px-4 py-3 text-sm {{ ($toast['type'] ?? 'success') === 'error' ? 'bg-red-50 text-red-900 ring-1 ring-red-200' : 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200' }}">{{ $toast['message'] }}</p>
            @endforeach
            @if(session('status'))
                <p class="mb-4 rounded-lg bg-white px-4 py-3 text-sm ring-1 ring-stone-200">{{ session('status') }}</p>
            @endif
        </div>

        @yield('content')
    </main>

    <footer class="mx-auto max-w-5xl px-4 pb-10 text-center text-xs text-gray-600">
        @if($settings)
            <a href="{{ route('portal.privacy', ['portalKey' => $settings->portal_key]) }}" class="underline underline-offset-2">{{ __('Så hanteras dina personuppgifter') }}</a>
        @endif
        @if(! $settings || $settings->show_powered_by)
            <p class="mt-2">{{ __('Kundportalen drivs med NONOX') }}</p>
        @endif
    </footer>
</body>
</html>
