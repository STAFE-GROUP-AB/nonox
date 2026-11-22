<!DOCTYPE html>
<html lang="sv">
<head>
    @include('signing.head', ['pageTitle' => __('Till tjänsten för underskrift')])
    {{-- A form may only post to NONOX (form-action 'self'), so the way to the provider is a plain navigation, not a redirect after the post. --}}
    <meta http-equiv="refresh" content="0;url={{ $url }}">
</head>
<body class="bg-stone-100 font-sans text-gray-900 antialiased">
    <main id="main" class="mx-auto max-w-xl px-4 pb-16 pt-10 sm:pt-16">
        <div class="rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Du skickas vidare till :provider', ['provider' => $provider]) }}</h1>
            <p class="mt-3 text-gray-700">{{ __('Där loggar du in och skriver under dokumentet med ditt eget certifikat. Sedan kommer du tillbaka hit.') }}</p>
            <a href="{{ $url }}" rel="noreferrer" class="mt-5 inline-flex min-h-12 items-center justify-center rounded-lg bg-ink px-5 text-base font-semibold text-white">{{ __('Fortsätt till :provider', ['provider' => $provider]) }}</a>
        </div>
        <p class="mt-8 text-center text-xs text-gray-600">NONOX</p>
    </main>
</body>
</html>
