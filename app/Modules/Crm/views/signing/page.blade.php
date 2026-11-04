@php
    use App\Support\Money\Money;

    $kr = fn (int $ore): string => Money::ofOre($ore)->format();
    $qty = fn (string $quantity): string => rtrim(rtrim(str_replace('.', ',', $quantity), '0'), ',');
    $zone = config('app.timezone');
    $input = 'mt-1 block w-full rounded-lg border-gray-300 shadow-xs focus:border-gray-900 focus:ring-gray-900';
    $deadline = $agreement?->noticeDeadline();
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
    @include('crm::signing.head', ['pageTitle' => __(':title att signera', ['title' => $title])])
</head>
<body class="bg-stone-100 font-sans text-gray-900 antialiased">
    <a href="#signera" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">{{ __('Hoppa till signeringen') }}</a>
    <div class="h-1.5" style="background: {{ $color }}"></div>
    <main id="main" class="mx-auto max-w-3xl px-4 pb-16 pt-6 sm:pt-10">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                @if($logo = $brand->logoDataUri())
                    <img src="{{ $logo }}" alt="{{ $companyName }}" class="mb-2 max-h-12 max-w-48">
                @endif
                <p class="text-sm font-semibold text-gray-700">{{ $companyName }}</p>
            </div>
            <p class="text-sm text-gray-600">{{ __('Länken gäller till och med :date', ['date' => $request->expires_at->setTimezone($zone)->format('Y-m-d')]) }}</p>
        </header>

        <h1 class="mt-6 text-2xl font-bold tracking-tight sm:text-3xl">{{ __(':title att signera', ['title' => $title]) }}</h1>
        <p class="mt-2 text-gray-700">{{ __('Läs igenom dokumentet. Signera längst ned med din namnteckning, eller avböj om du inte vill godkänna det.') }}</p>

        <section aria-labelledby="dokument" class="mt-6 rounded-2xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="dokument" class="text-lg font-semibold">{{ __('Dokumentet') }}</h2>
                <a href="{{ route('crm.signing.document', ['token' => $token]) }}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold underline underline-offset-2">{{ __('Öppna PDF (:name)', ['name' => $request->document_name]) }}</a>
            </div>

            @if($quote)
                <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                    <div><dt class="text-gray-600">{{ __('Offertnummer') }}</dt><dd class="font-medium tabular-nums">{{ $quote->number }}</dd></div>
                    <div><dt class="text-gray-600">{{ __('Datum') }}</dt><dd class="font-medium tabular-nums">{{ $quote->document_date->toDateString() }}</dd></div>
                    @if($quote->until_date)
                        <div><dt class="text-gray-600">{{ __('Giltig till') }}</dt><dd class="font-medium tabular-nums">{{ $quote->until_date->toDateString() }}</dd></div>
                    @endif
                    <div class="sm:col-span-3"><dt class="text-gray-600">{{ __('Till') }}</dt><dd class="font-medium">{{ $quote->customer?->name }}</dd></div>
                </dl>

                {{-- On a phone the rows are a list; from sm and up a table. --}}
                <ul class="mt-5 divide-y divide-gray-100 border-y border-gray-200 text-sm sm:hidden">
                    @foreach($quote->rows as $row)
                        <li class="py-3">
                            <span class="flex justify-between gap-3"><span class="font-medium">{{ $row->description }}</span><span class="tabular-nums">{{ $kr($row->net_ore->ore) }}</span></span>
                            <span class="block text-gray-600 tabular-nums">{{ $qty($row->quantity) }} {{ $row->unit }} × {{ $kr($row->unit_price_ore->ore) }} · {{ __('moms :rate %', ['rate' => $row->vat_rate / 100]) }}@if($row->discount_bp > 0) · {{ __('rabatt :percent %', ['percent' => str_replace('.', ',', (string) ($row->discount_bp / 100))]) }}@endif</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-5 hidden overflow-x-auto sm:block">
                    <table class="w-full min-w-[32rem] text-sm">
                        <caption class="sr-only">{{ __('Rader') }}</caption>
                        <thead>
                            <tr class="border-b border-gray-300 text-left text-gray-600">
                                <th scope="col" class="py-2 pr-3 font-normal">{{ __('Benämning') }}</th>
                                <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Antal') }}</th>
                                <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('À-pris') }}</th>
                                <th scope="col" class="py-2 pr-3 text-right font-normal">{{ __('Moms') }}</th>
                                <th scope="col" class="py-2 text-right font-normal">{{ __('Belopp') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($quote->rows as $row)
                                <tr class="border-b border-gray-100 align-top">
                                    <td class="py-2 pr-3">{{ $row->description }}@if($row->discount_bp > 0) <span class="text-gray-600">({{ __('rabatt :percent %', ['percent' => str_replace('.', ',', (string) ($row->discount_bp / 100))]) }})</span>@endif</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ $qty($row->quantity) }} {{ $row->unit }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ $kr($row->unit_price_ore->ore) }}</td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ $row->vat_rate / 100 }} %</td>
                                    <td class="py-2 text-right tabular-nums">{{ $kr($row->net_ore->ore) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="ms-auto mt-4 max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Summa exkl. moms') }}</dt><dd class="tabular-nums">{{ $kr($quote->net_ore->ore) }}</dd></div>
                    @foreach(($vat['vat_by_rate'] ?? []) as $rate => $group)
                        @if($group['vat'] !== 0)
                            <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Moms :rate %', ['rate' => $rate / 100]) }}</dt><dd class="tabular-nums">{{ $kr($group['vat']) }}</dd></div>
                        @endif
                    @endforeach
                    @if($quote->rounding_ore->ore !== 0)
                        <div class="flex justify-between gap-4"><dt class="text-gray-600">{{ __('Öresavrundning') }}</dt><dd class="tabular-nums">{{ $kr($quote->rounding_ore->ore) }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-4 border-t border-gray-300 pt-2 text-base font-bold"><dt>{{ __('Att betala') }}</dt><dd class="tabular-nums">{{ $kr($quote->total_ore->ore) }}</dd></div>
                </dl>

                @if($quote->notes)
                    <div class="mt-5 border-t border-gray-100 pt-4 text-sm">
                        <h3 class="font-semibold">{{ __('Villkor och meddelande') }}</h3>
                        <p class="mt-1 whitespace-pre-line text-gray-800">{{ $quote->notes }}</p>
                    </div>
                @endif
            @elseif($agreement)
                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div class="sm:col-span-2"><dt class="text-gray-600">{{ __('Avtal') }}</dt><dd class="font-medium">{{ $agreement->title }} · {{ $agreement->kind->label() }}</dd></div>
                    <div><dt class="text-gray-600">{{ __('Parter') }}</dt><dd class="font-medium">{{ $companyName }} {{ __('och') }} {{ $agreement->account?->name }}</dd></div>
                    <div><dt class="text-gray-600">{{ __('Period') }}</dt><dd class="font-medium tabular-nums">{{ $agreement->start_date->toDateString() }} – {{ $agreement->end_date?->toDateString() ?? __('tills vidare') }}</dd></div>
                    <div><dt class="text-gray-600">{{ __('Uppsägningstid') }}</dt><dd class="font-medium">{{ $agreement->notice_months === 0 ? __('Ingen') : ($agreement->notice_months === 1 ? __('1 månad') : __(':count månader', ['count' => $agreement->notice_months])) }}@if($deadline) ({{ __('senast :date', ['date' => $deadline->toDateString()]) }})@endif</dd></div>
                    <div><dt class="text-gray-600">{{ __('Förlängning') }}</dt><dd class="font-medium">{{ $agreement->auto_renew && $agreement->renewal_months ? __(':months månader i taget', ['months' => $agreement->renewal_months]) : __('Förlängs inte automatiskt') }}</dd></div>
                    @if($agreement->value_ore > 0)
                        <div><dt class="text-gray-600">{{ __('Värde exkl. moms') }}</dt><dd class="font-medium tabular-nums">{{ $kr($agreement->value_ore) }} {{ $agreement->value_period->label() }}</dd></div>
                    @endif
                </dl>
                @if($agreement->document_path === null && $agreement->body_text)
                    <div class="mt-5 border-t border-gray-100 pt-4 text-sm">
                        <h3 class="font-semibold">{{ __('Villkor') }}</h3>
                        <p class="mt-1 whitespace-pre-line text-gray-800">{{ $agreement->body_text }}</p>
                    </div>
                @endif
            @endif

            <iframe src="{{ route('crm.signing.document', ['token' => $token]) }}" title="{{ __('Dokumentet som PDF') }}" class="mt-6 hidden h-[36rem] w-full rounded-lg ring-1 ring-stone-200 md:block"></iframe>

            <p class="mt-4 break-all text-xs text-gray-600">{{ __('Kontrollsumma för PDF:en (SHA-256): :hash', ['hash' => $request->document_sha256]) }}</p>
        </section>

        <section id="signera" aria-labelledby="signera-rubrik" class="mt-6 rounded-2xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6">
            <h2 id="signera-rubrik" class="text-lg font-semibold">{{ __('Signera') }}</h2>

            @if($errors->any())
                <div role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-800 ring-1 ring-red-200">
                    <p class="font-semibold">{{ __('Något behöver rättas:') }}</p>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('crm.signing.sign', ['token' => $token]) }}" class="mt-4 space-y-5" data-signing-form
                data-empty-text="{{ __('Rita din namnteckning i rutan, eller välj att skriva ditt namn.') }}"
                data-typed-text="{{ __('Skriv ditt namn exakt som i namnfältet för att skriva under.') }}"
                data-drawn-text="{{ __('Namnteckningen är ritad.') }}"
                data-busy-text="{{ __('Signerar…') }}">
                @csrf
                <input type="hidden" name="document_sha256" value="{{ $request->document_sha256 }}">
                <input type="hidden" name="acceptance_text" value="{{ $acceptance }}">
                <input type="hidden" name="acceptance_mac" value="{{ $acceptanceMac }}">
                <input type="hidden" name="signature" value="" data-signature-input>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="signer-name" class="text-sm font-medium">{{ __('Ditt namn') }}</label>
                        <input id="signer-name" name="name" type="text" autocomplete="name" required maxlength="120" value="{{ old('name') }}" class="{{ $input }}" @error('name') aria-invalid="true" @enderror>
                    </div>
                    <div>
                        <label for="signer-title" class="text-sm font-medium">{{ __('Titel (frivilligt)') }}</label>
                        <input id="signer-title" name="title" type="text" autocomplete="organization-title" maxlength="120" value="{{ old('title') }}" class="{{ $input }}">
                    </div>
                </div>

                <fieldset>
                    <legend class="text-sm font-medium">{{ __('Hur vill du skriva under?') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-4 text-sm">
                        <label class="inline-flex min-h-11 items-center gap-2"><input type="radio" name="method" value="DRAWN" @checked(old('method', 'DRAWN') === 'DRAWN')> {{ __('Rita namnteckningen') }}</label>
                        <label class="inline-flex min-h-11 items-center gap-2"><input type="radio" name="method" value="TYPED" @checked(old('method') === 'TYPED')> {{ __('Skriv mitt namn') }}</label>
                    </div>
                </fieldset>

                <div data-method-panel="DRAWN">
                    <p id="pad-help" class="text-sm text-gray-600">{{ __('Rita med musen, fingret eller pennan i rutan.') }}</p>
                    <canvas width="1200" height="400" data-signature-canvas tabindex="-1" role="img" aria-label="{{ __('Ruta för namnteckning') }}" aria-describedby="pad-help"
                        class="mt-2 block aspect-[3/1] w-full touch-none rounded-lg bg-white ring-2 ring-gray-300 ring-offset-1"></canvas>
                    <div class="mt-2 flex flex-wrap gap-3">
                        <button type="button" data-signature-undo class="min-h-11 rounded-lg px-3 text-sm font-semibold ring-1 ring-gray-300 hover:bg-gray-50">{{ __('Ångra senaste draget') }}</button>
                        <button type="button" data-signature-clear class="min-h-11 rounded-lg px-3 text-sm font-semibold ring-1 ring-gray-300 hover:bg-gray-50">{{ __('Rensa') }}</button>
                    </div>
                    <p class="mt-1 text-sm text-gray-700" data-signature-status role="status" hidden></p>
                </div>

                <div data-method-panel="TYPED" hidden>
                    <label for="typed-signature" class="text-sm font-medium">{{ __('Skriv ditt namn igen som underskrift') }}</label>
                    <input id="typed-signature" name="typed_signature" type="text" maxlength="120" value="{{ old('typed_signature') }}" class="{{ $input }} font-serif text-xl italic" autocomplete="off">
                </div>

                <p class="text-sm font-medium text-red-700" data-signature-error role="alert" hidden></p>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="accept" value="1" required class="mt-0.5 size-5 rounded border-gray-400" @checked(old('accept'))>
                    <span>{{ $acceptance }}</span>
                </label>

                <p class="text-xs text-gray-600">{{ __('När du signerar sparas ditt namn, din titel, din namnteckning, tidpunkten, din IP-adress och din webbläsare som bevis på att avtalet ingicks, tillsammans med dokumentets kontrollsumma. Personuppgiftsansvarig är :controller. Uppgifterna behandlas för att ingå avtalet och kunna visa att det ingicks (artikel 6.1 b och f i dataskyddsförordningen) och sparas så länge avtalet och bokföringslagen kräver, normalt sju år. NONOX behandlar dem för :company:s räkning. Du har rätt att begära tillgång, rättelse och i vissa fall radering eller begränsning, och att klaga hos Integritetsskyddsmyndigheten (IMY). Du får en kopia med e-post.', ['company' => $companyName, 'controller' => $controller]) }}</p>

                <button type="submit" data-signing-submit class="inline-flex min-h-12 w-full items-center justify-center rounded-lg px-5 text-base font-semibold text-white sm:w-auto" style="background: {{ $color }}">{{ __('Signera') }}</button>
            </form>
        </section>

        <details class="mt-6 rounded-2xl bg-white p-5 shadow-xs ring-1 ring-stone-200 sm:p-6">
            <summary class="cursor-pointer text-sm font-semibold">{{ __('Vill du inte godkänna? Avböj') }}</summary>
            <form method="POST" action="{{ route('crm.signing.decline', ['token' => $token]) }}" class="mt-4 space-y-3" data-decline-form>
                @csrf
                <label for="decline-reason" class="text-sm font-medium">{{ __('Berätta gärna varför (frivilligt)') }}</label>
                <textarea id="decline-reason" name="reason" rows="3" maxlength="1000" class="{{ $input }}"></textarea>
                <p class="text-xs text-gray-600">{{ __('Avböjer du sparas tidpunkten och ditt skäl hos :company i högst ett år.', ['company' => $companyName]) }}</p>
                <button type="submit" class="min-h-11 rounded-lg px-4 text-sm font-semibold ring-1 ring-gray-400 hover:bg-gray-50">{{ __('Avböj') }}</button>
            </form>
        </details>

        <p class="mt-8 text-center text-xs text-gray-600">{{ __('Skickat av :company med NONOX. En enkel elektronisk underskrift.', ['company' => $companyName]) }}</p>
    </main>
</body>
</html>
