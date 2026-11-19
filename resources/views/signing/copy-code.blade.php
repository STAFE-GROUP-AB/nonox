                @php($input = 'mt-1 block min-h-12 w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900')
                <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ __('Skriv in en kod för att ladda ner filerna') }}</h1>
                <p class="mt-3 text-gray-700">{{ __('Det här är en konfidentiell signering. Vi skickar en kod till :email, och med den öppnar du filerna.', ['email' => $maskedEmail]) }}</p>

                @if(session('signing-download-code'))
                    <p class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900 ring-1 ring-emerald-200" role="status">{{ session('signing-download-code') }}</p>
                @endif

                @if($errors->any())
                    <div role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                        @foreach($errors->all() as $message)
                            <p>{{ $message }}</p>
                        @endforeach
                    </div>
                @endif

                @if($locked)
                    <p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200" role="alert" data-testid="download-code-locked">{{ __('För många felaktiga försök. Be avsändaren skicka en ny länk.') }}</p>
                @elseif($testCompany)
                    <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" role="status">{{ __('Det här är ett testföretag i NONOX. Testföretag skickar inga mejl, så ingen kod kan skickas.') }}</p>
                @elseif(! $codeSent)
                    <form method="POST" action="{{ route('signing.copy.code', ['token' => $token]) }}" class="mt-6" data-once-form>
                        @csrf
                        <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg px-5 text-base font-semibold text-white sm:w-auto" style="background: {{ $color }}">{{ __('Skicka kod') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('signing.copy.verify', ['token' => $token]) }}" class="mt-6 space-y-4" data-once-form>
                        @csrf
                        <div>
                            <label for="download-code" class="text-sm font-medium">{{ __('Kod från e-posten') }}</label>
                            <input id="download-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus class="{{ $input }} max-w-48 text-center font-mono text-2xl tracking-[0.4em]">
                            <p class="mt-1 text-xs text-gray-600">{{ __('Koden gäller i :minutes minuter och kan användas en gång.', ['minutes' => $minutes]) }}</p>
                        </div>
                        <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-lg px-5 text-base font-semibold text-white sm:w-auto" style="background: {{ $color }}">{{ __('Fortsätt') }}</button>
                    </form>
                    <form method="POST" action="{{ route('signing.copy.code', ['token' => $token]) }}" class="mt-4" data-once-form>
                        @csrf
                        <p class="text-sm text-gray-600">{{ __('Ingen kod? Titta i skräpposten, eller') }} <button type="submit" class="min-h-11 font-semibold underline underline-offset-2">{{ __('skicka en ny kod') }}</button></p>
                    </form>
                @endif
