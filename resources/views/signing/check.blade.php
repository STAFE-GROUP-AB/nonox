@php
    $input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900';
    $codeSent = $openCode || session('signing-code') !== null || session('signing-code-sent') === true;
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
    @include('signing.head', ['pageTitle' => __('Bekräfta att det är du')])
</head>
<body class="bg-stone-100 font-sans text-gray-900 antialiased">
    <div class="h-1.5" style="background: {{ $color }}"></div>
    <main id="main" class="mx-auto max-w-xl px-4 pb-16 pt-6 sm:pt-10">
        <header>
            @if($logo = $brand->logoDataUri())
                <img src="{{ $logo }}" alt="{{ $companyName }}" class="mb-2 max-h-12 max-w-48">
            @endif
            <p class="text-sm font-semibold text-gray-700">{{ $companyName }}</p>
        </header>

        <div class="mt-6 rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8" data-testid="signing-check">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Bekräfta att det är du') }}</h1>

            @if($locked)
                <p class="mt-3 text-gray-700" role="alert">{{ __('Länken är låst efter för många felaktiga försök. Kontakta :company, som kan låsa upp den åt dig.', ['company' => $companyName]) }}</p>
            @else
                <p class="mt-3 text-gray-700">
                    {{ $emailCode
                        ? __(':company har skickat ett dokument som du ska signera och vill att du bekräftar din e-postadress först. Vi skickar en kod med sex siffror till :email.', ['company' => $companyName, 'email' => $maskedEmail])
                        : __(':company har skickat ett dokument som du ska signera och har gett dig en åtkomstkod på annat sätt, till exempel per telefon. Koden skickas aldrig med e-post.', ['company' => $companyName]) }}
                </p>

                @if(session('signing-code'))
                    <p class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900 ring-1 ring-emerald-200" role="status">{{ session('signing-code') }}</p>
                @endif

                @if($errors->any())
                    <div role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                        @foreach($errors->all() as $message)
                            <p>{{ $message }}</p>
                        @endforeach
                    </div>
                @endif

                @if($emailCode && $testCompany)
                    <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" role="status" data-testid="signing-check-test-company">{{ __('Det här är ett testföretag i NONOX. Testföretag skickar inga mejl, så ingen kod kan skickas. Be avsändaren skicka med en åtkomstkod i stället.') }}</p>
                @elseif($emailCode && ! $codeSent)
                    <form method="POST" action="{{ route('signing.code', ['token' => $token]) }}" class="mt-6" data-once-form>
                        @csrf
                        <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg px-5 text-base font-semibold text-white sm:w-auto" style="background: {{ $color }}">{{ __('Skicka kod') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('signing.verify', ['token' => $token]) }}" class="mt-6 space-y-4" data-once-form>
                        @csrf
                        @if($emailCode)
                            <div>
                                <label for="signing-code" class="text-sm font-medium">{{ __('Kod från e-posten') }}</label>
                                <input id="signing-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus class="{{ $input }} max-w-48 text-center font-mono text-2xl tracking-[0.4em]" @error('code') aria-invalid="true" aria-describedby="signing-code-error" @enderror>
                            </div>
                        @else
                            <div>
                                <label for="signing-code" class="text-sm font-medium">{{ __('Åtkomstkod') }}</label>
                                <input id="signing-code" name="code" type="password" autocomplete="off" maxlength="64" required autofocus class="{{ $input }}" @error('code') aria-invalid="true" @enderror>
                            </div>
                        @endif
                        <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg px-5 text-base font-semibold text-white sm:w-auto" style="background: {{ $color }}">{{ __('Fortsätt') }}</button>
                    </form>

                    @if($emailCode)
                        <form method="POST" action="{{ route('signing.code', ['token' => $token]) }}" class="mt-4" data-once-form>
                            @csrf
                            <p class="text-sm text-gray-600">{{ __('Ingen kod? Titta i skräpposten, eller') }} <button type="submit" class="min-h-11 font-semibold underline underline-offset-2">{{ __('skicka en ny kod') }}</button></p>
                        </form>
                    @endif
                @endif
            @endif
        </div>

        <p class="mt-6 text-xs text-gray-600">{{ __('Kontrollen minskar risken att någon annan använder en vidarebefordrad länk. Underskriften blir fortfarande en enkel elektronisk underskrift. Efter kontrollen kan du läsa dokumentet i den här webbläsaren i 30 minuter.') }}</p>
        <p class="mt-8 text-center text-xs text-gray-600">{{ __('Skickat av :company med NONOX.', ['company' => $companyName]) }}</p>
    </main>
</body>
</html>
