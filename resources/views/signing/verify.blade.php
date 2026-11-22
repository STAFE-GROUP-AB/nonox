<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Kontrollera ett signerat dokument') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/nonox-icon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/verify.js'])
</head>
<body class="bg-stone-100 font-sans text-gray-900 antialiased">
    <main id="main" class="mx-auto max-w-xl px-4 pb-16 pt-10 sm:pt-16" data-verify data-verify-url="{{ route('signing.verifyCheck') }}" data-verify-text="{{ json_encode($text, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}" data-testid="verify-page">
        <div class="rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Kontrollera ett signerat dokument') }}</h1>
            <p class="mt-3 text-gray-700">{{ __('Välj en PDF-fil för att kontrollera att den är oförändrad jämfört med ett dokument som NONOX har sparat. Sidan kontrollerar inte vem som har skrivit under eller om en underskrift är giltig.') }}</p>

            <p class="mt-4 rounded-lg bg-stone-50 p-3 text-sm text-gray-700 ring-1 ring-stone-200" data-testid="verify-privacy">
                {{ __('Filen lämnar aldrig din enhet. Din webbläsare räknar ut dess kontrollsumma (SHA-256), och bara de 64 tecknen skickas hit.') }}
                <span lang="en" class="mt-1 block">The file never leaves your device. Your browser computes its checksum (SHA-256) and only those 64 characters are sent here.</span>
            </p>

            <div class="mt-6">
                <label for="verify-file" class="block text-sm font-medium text-gray-700">{{ __('Välj fil') }}</label>
                <input id="verify-file" type="file" accept="application/pdf,.pdf" data-verify-file class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-gray-700">
            </div>

            <p class="mt-4 min-h-6 text-sm text-gray-700" role="status" aria-live="polite" data-verify-status></p>

            <section class="mt-2" tabindex="-1" hidden data-verify-result data-testid="verify-result">
                <h2 class="text-lg font-semibold" data-verify-heading></h2>
                <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-[10rem_1fr] [&>dt]:mt-2 [&>dt]:font-medium [&>dt]:text-gray-600 sm:[&>dt]:mt-0 [&>dd]:break-words" data-verify-list></dl>
                <p class="mt-4 text-sm text-gray-700" data-verify-note></p>
            </section>

            <noscript>
                <p class="mt-4 text-sm text-red-700">{{ __('Sidan behöver JavaScript för att kunna räkna ut filens kontrollsumma på din enhet.') }}</p>
            </noscript>
        </div>
        <p class="mt-8 text-center text-xs text-gray-600">NONOX</p>
    </main>
</body>
</html>
