{{--
    Momsbufferten (App\Domain\Guide\MoneyNotYours, ADR-051 stage 3): what of
    the money in the bank is not the company's, read from the books. On
    Översikt the short form; on Mina datum (:full) also the VAT of each coming
    declaration and "Så räknar vi" with every account and its balance.
--}}
@props(['figures', 'company', 'full' => false, 'vatDeadlines' => [], 'articleUrl' => null])

@php
    $kr = fn (int $ore): string => App\Domain\Guide\MoneyNotYoursFigures::kronor($ore);
    $bank = $figures->bankTotal();
    $vat = $figures->vatTotal();
    $employer = $figures->employerTotal();
    $tax = $figures->taxAccount;
    $prelim = $figures->preliminaryTax;
    $remaining = $figures->remaining();
    $settingsUrl = route('teams.show', $company->id);
    $date = fn (?Carbon\CarbonImmutable $day): string => $day?->translatedFormat('j F Y') ?? '';
@endphp

<div {{ $attributes->class(['grid gap-6 lg:grid-cols-2' => $full, 'space-y-4' => ! $full]) }} data-money-not-yours>
    <div class="min-w-0 space-y-4">
    @if($remaining !== null)
        <div class="rounded-2xl bg-stone-50 p-4 ring-1 ring-stone-200">
            <p class="text-sm font-semibold text-gray-700">{{ __('Kvar efter moms och skatter') }}</p>
            <p @class(['mt-1 text-3xl font-bold tabular-nums tracking-tight', 'text-ink' => $remaining >= 0, 'text-accent-700' => $remaining < 0]) data-money-remaining>{{ $kr($remaining) }}</p>
            <p class="mt-1 text-sm text-gray-600">{{ __('Pengarna på banken minus det som ska till Skatteverket.') }}</p>
        </div>
    @endif

    <dl class="divide-y divide-stone-100 text-sm">
        @if($bank !== null)
            <div class="flex items-baseline justify-between gap-4 py-2">
                <dt class="text-gray-700">{{ __('På banken och i kassan') }}</dt>
                <dd class="font-semibold tabular-nums text-ink">{{ $kr($bank) }}</dd>
            </div>
        @endif
        @if($vat !== null)
            <div class="flex items-baseline justify-between gap-4 py-2" data-money-vat>
                <dt class="text-gray-700">
                    @if($vat >= 0)
                        {{ __('Moms att betala') }}
                    @else
                        {{ __('Moms att få tillbaka') }}
                        <span class="block text-xs text-gray-500">{{ __('Räknas inte in: pengarna är inte på kontot än.') }}</span>
                    @endif
                </dt>
                <dd class="font-semibold tabular-nums text-ink">{{ $vat >= 0 ? '−' : '' }}{{ $kr(abs($vat)) }}</dd>
            </div>
        @endif
        @if($employer !== null)
            <div class="flex items-baseline justify-between gap-4 py-2" data-money-employer>
                <dt class="text-gray-700">{{ __('Personalskatt och arbetsgivaravgifter') }}</dt>
                <dd class="font-semibold tabular-nums text-ink">−{{ $kr(max($employer, 0)) }}</dd>
            </div>
        @endif
        @if($prelim !== null && $prelim['count'] > 0)
            <div class="flex items-baseline justify-between gap-4 py-2" data-money-preliminary-tax>
                <dt class="text-gray-700">
                    {{ $prelim['until'] ? __($prelim['count'] === 1 ? 'Preliminärskatt, :count betalning till :date' : 'Preliminärskatt, :count betalningar till :date', ['count' => $prelim['count'], 'date' => str_replace(' ', "\u{00A0}", Carbon\CarbonImmutable::parse($prelim['until'])->translatedFormat('j F'))]) : __('Preliminärskatt, nästa betalning') }}
                    <span class="block text-xs text-gray-500">{{ __('Enligt beloppet du har angett från beslutet.') }}</span>
                </dt>
                <dd class="font-semibold tabular-nums text-ink">−{{ $kr($prelim['total']) }}</dd>
            </div>
        @endif
        @if($tax !== null && $tax['amount'] !== 0)
            <div class="flex items-baseline justify-between gap-4 py-2" data-money-tax-account>
                <dt class="text-gray-700">{{ $tax['amount'] > 0 ? __('Redan på skattekontot') : __('Skuld på skattekontot') }}</dt>
                <dd class="font-semibold tabular-nums text-ink">{{ $tax['amount'] > 0 ? '+' : '−' }}{{ $kr(abs($tax['amount'])) }}</dd>
            </div>
        @endif
    </dl>

    @if($figures->vatExceedsBank())
        <div class="rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-950 ring-1 ring-amber-200" role="note" data-money-warning>
            <p class="font-semibold">{{ __('Momsen att betala är mer än pengarna på banken') }}</p>
            <p class="mt-1">{{ __('Det är vanligt när en stor försäljning ännu inte är betald eller pengarna har gått till inköp. Se till att det finns pengar på kontot till deklarationsdagen.') }}
                @if($articleUrl) <a href="{{ $articleUrl }}" class="font-semibold underline">{{ __('Så sätter du undan pengar för moms och skatt') }}</a> @endif
            </p>
        </div>
    @endif

    </div>

    <div class="min-w-0 space-y-4">
    @if($vatDeadlines !== [])
        <div data-money-vat-deadlines>
            <h3 class="text-sm font-bold text-ink">{{ __('Momsen per deklaration') }}</h3>
            <ul class="mt-2 space-y-1.5 text-sm">
                @foreach($vatDeadlines as $item)
                    <li class="flex items-baseline justify-between gap-x-4">
                        <span class="min-w-0 text-gray-700">{{ __('Till momsdeklarationen :date', ['date' => str_replace(' ', "\u{00A0}", $item['deadline']->date->translatedFormat('j F'))]) }} <span class="text-gray-500">({{ $item['running'] ? __(':period hittills', ['period' => $item['deadline']->period]) : $item['deadline']->period }})</span></span>
                        <span class="whitespace-nowrap font-semibold tabular-nums text-ink">{{ $item['amount'] >= 0 ? __('ca :amount', ['amount' => App\Domain\Guide\MoneyNotYoursFigures::approximately($item['amount'])]) : __('ca :amount tillbaka', ['amount' => App\Domain\Guide\MoneyNotYoursFigures::approximately(-$item['amount'])]) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="text-xs text-gray-600" data-money-note>
        {{ __('Bygger på det som är bokfört till och med :date. Obokförda fakturor och kvitton syns inte.', ['date' => $date($figures->asOf)]) }}
        @if($figures->lastVoucher) {{ __('Senast bokförda verifikation: :date.', ['date' => $date($figures->lastVoucher)]) }} @endif
    </p>

    @if($full)
        @if($company->f_skatt && $prelim === null)
            <p class="text-sm text-gray-700">{{ __('Vill du se kommande preliminärskatt här? Fyll i beloppet från beslutet om debiterad preliminärskatt under Företagets uppgifter.') }} <a href="{{ $settingsUrl }}" class="font-medium text-accent-700 underline">{{ __('Ändra under Företagets uppgifter') }}</a></p>
        @endif

        <details class="group rounded-2xl bg-white ring-1 ring-stone-200" data-money-details>
            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-4 py-2 text-sm font-semibold text-ink [&::-webkit-details-marker]:hidden">
                <svg class="size-4 transition group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                {{ __('Så räknar vi') }}
            </summary>
            <div class="space-y-4 border-t border-stone-100 px-4 py-3 text-sm text-gray-700">
                <p>{{ __('Siffrorna är saldot på kontona i bokföringen, med bokförda verifikationer till och med i dag och räkenskapsårets ingående balanser. Utkast räknas inte.') }}</p>
                @php
                    $groups = array_filter([
                        __('Bank och kassa (1900–1999)') => $figures->bank,
                        __('Moms som ännu inte är redovisad') => $figures->vatUnreported,
                        __('Moms som är redovisad men inte betald') => $figures->vatReported === null ? null : [$figures->vatReported],
                        __('Personalskatt och arbetsgivaravgifter') => $figures->employer,
                        __('Skattekontot') => $tax === null ? null : [$tax],
                    ], fn ($lines) => $lines !== null);
                @endphp
                @foreach($groups as $heading => $lines)
                    <div>
                        <h4 class="font-semibold text-ink">{{ $heading }}</h4>
                        @if($lines === [])
                            <p class="text-gray-600">{{ __('Inget saldo.') }}</p>
                        @else
                            <table class="mt-1 w-full">
                                <tbody>
                                    @foreach($lines as $line)
                                        <tr>
                                            <td class="w-16 py-0.5 tabular-nums text-gray-600">{{ $line['number'] }}</td>
                                            <td class="py-0.5">{{ $line['name'] }}</td>
                                            <td class="py-0.5 text-right tabular-nums">{{ $kr($line['amount']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                @endforeach
                @if($prelim !== null)
                    <p><strong class="text-ink">{{ __('Preliminärskatt') }}</strong>: {{ __(':amount i månaden, som du har angett från beslutet, gånger :count betalningsdagar fram till nästa momsdeklaration. Den är inte bokförd förrän den betalas.', ['amount' => $kr($prelim['monthly']), 'count' => $prelim['count']]) }}</p>
                @endif
                <p class="rounded-xl bg-stone-50 px-3 py-2">{{ __('Kvar = bank och kassa + skattekontot − moms att betala − personalskatt och arbetsgivaravgifter − kommande preliminärskatt. Moms att få tillbaka räknas inte in.') }}</p>
            </div>
        </details>
    @endif
    </div>
</div>
