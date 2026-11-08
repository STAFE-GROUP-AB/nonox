<!DOCTYPE html>
<html lang="sv">
<head>
    @include('signing.head', ['pageTitle' => $signedNow ? __('Tack för din underskrift') : __('Signering')])
</head>
<body class="bg-stone-100 font-sans text-gray-900 antialiased">
    <main id="main" class="mx-auto max-w-xl px-4 pb-16 pt-10 sm:pt-16">
        <div class="rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8">
            @if($companyName)
                <p class="text-sm font-semibold text-gray-700">{{ $companyName }}</p>
            @endif
            @if($signedNow)
                <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ __('Tack! Dokumentet är signerat.') }}</h1>
                <p class="mt-3 text-gray-700">{{ __('Du får en kopia av dokumentet och signeringsbeviset med e-post inom några minuter. Du kan stänga sidan.') }}</p>
            @elseif($declinedNow)
                <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ __('Du har avböjt dokumentet.') }}</h1>
                <p class="mt-3 text-gray-700">{{ __('Avsändaren får veta det. Du kan stänga sidan.') }}</p>
            @else
                <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ $reason === App\Domain\Signing\SigningUnavailable::SIGNED ? __('Dokumentet är signerat') : __('Länken kan inte användas') }}</h1>
                <p class="mt-3 text-gray-700">{{ $message }}</p>
            @endif
        </div>
        <p class="mt-8 text-center text-xs text-gray-600">NONOX</p>
    </main>
</body>
</html>
