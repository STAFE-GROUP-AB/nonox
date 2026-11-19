<!DOCTYPE html>
<html lang="sv">
<head>
    @include('signing.head', ['pageTitle' => __('Samtycke till elektroniska handlingar')])
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

        <div class="mt-6 rounded-2xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8" data-testid="signing-consent">
            @if($refused)
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Du har inte skrivit under') }}</h1>
                <p class="mt-3 text-gray-700" role="status">{{ __('Kontakta avsändaren om du vill skriva under på annat sätt. Vi har meddelat :company att du inte vill skriva under elektroniskt.', ['company' => $companyName]) }}</p>
                <p class="mt-3 text-sm text-gray-600">{{ __('Ändrar du dig kan du öppna länken igen så länge den gäller.') }}</p>
            @else
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Samtycke till elektroniska handlingar') }}</h1>
                <p class="mt-2 text-sm text-gray-600">{{ __(':company vill att du skriver under :title.', ['company' => $companyName, 'title' => $title]) }}</p>

                @if($errors->any())
                    <div role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                        @foreach($errors->all() as $message)
                            <p>{{ $message }}</p>
                        @endforeach
                    </div>
                @endif

                {{-- The text exactly as hashed (SigningConsent::canonical); never translated through __(), so the hash is of what is shown. --}}
                <div class="mt-4 space-y-3 text-gray-800">
                    @foreach($consentText['paragraphs'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>

                @if($testCompany)
                    <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" role="status" data-testid="signing-consent-test-company">{{ __('Det här är ett testföretag i NONOX. Testföretag skickar inga mejl, så ingen kopia skickas med e-post.') }}</p>
                @endif

                <form method="POST" action="{{ route('signing.consent', ['token' => $token]) }}" class="mt-6 space-y-5" data-once-form>
                    @csrf
                    <input type="hidden" name="consent_version" value="{{ $consentVersion }}">
                    <input type="hidden" name="consent_text_sha256" value="{{ $consentSha256 }}">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="consent" value="1" required class="mt-0.5 size-5 rounded border-gray-400" @error('consent') aria-invalid="true" @enderror>
                        <span class="font-medium">{{ $consentText['statement'] }}</span>
                    </label>
                    <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg px-5 text-base font-semibold text-white sm:w-auto" style="background: {{ $color }}">{{ __('Fortsätt till dokumentet') }}</button>
                </form>

                <form method="POST" action="{{ route('signing.consentRefuse', ['token' => $token]) }}" class="mt-6 border-t border-gray-100 pt-4" data-once-form>
                    @csrf
                    <button type="submit" class="min-h-11 text-sm font-semibold underline underline-offset-2">{{ __('Jag vill inte skriva under elektroniskt') }}</button>
                </form>

                <p class="mt-6 text-xs text-gray-600">{{ __('Version :version. Ditt svar sparas med tidpunkten och textens kontrollsumma som en del av beviset för signeringen.', ['version' => $consentVersion]) }} {{ __('Personuppgiftsansvarig är :controller. Svaret behandlas för att kunna visa hur handlingen skrevs under (artikel 6.1 f i dataskyddsförordningen) och sparas lika länge som beviset om signeringen. NONOX behandlar det för :company:s räkning. Du har rätt att begära tillgång och att klaga hos Integritetsskyddsmyndigheten (IMY).', ['controller' => $controller, 'company' => $companyName]) }}</p>
            @endif
        </div>

        <p class="mt-8 text-center text-xs text-gray-600">{{ __('Skickat av :company med NONOX.', ['company' => $companyName]) }}</p>
    </main>
</body>
</html>
