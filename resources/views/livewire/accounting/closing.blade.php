@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $ore): string => \App\Support\Money\Money::ofOre($ore)->format();
    $done = collect($items)->where('status', '!=', 'todo')->count();
    $profit = -$result->beforeTaxOre;
@endphp
<div class="space-y-6">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Välj räkenskapsår') }}">
        @foreach($years as $option)
            <button type="button" wire:key="year-{{ $option->id }}" wire:click="$set('year', {{ $option->id }})" @class([
                'rounded-full px-4 py-2 text-sm font-semibold transition',
                'bg-ink text-white' => $option->id === $fiscalYear->id,
                'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => $option->id !== $fiscalYear->id,
            ]) @if($option->id === $fiscalYear->id) aria-current="true" @endif>{{ $option->label() }} @if(! $option->isOpen())<span class="font-normal opacity-70">· {{ __('Stängt') }}</span>@endif</button>
        @endforeach
    </nav>

    @if($k2Conditions !== null)
        <section class="rounded-3xl bg-amber-50 px-5 py-4 ring-1 ring-amber-300" aria-labelledby="k2-conditions-title" data-k2-conditions>
            <h2 id="k2-conditions-title" class="font-semibold text-ink">{{ __('Bekräfta villkoren för K2 innan du fortsätter') }}</h2>
            <p class="mt-1 text-sm text-gray-800">{{ __('Villkoren för K2 har ändrats genom BFNAR 2025:2 och gäller för räkenskapsår som börjar efter den 31 december 2025. Du bekräftar dem en gång för företaget, och bokslutet kan inte fortsätta innan du har gjort det.') }}</p>
            <div class="mt-3 space-y-2 text-sm text-gray-800">
                @foreach(explode("\n\n", $k2Conditions) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-button type="button" wire:click="confirmK2Conditions" wire:loading.attr="disabled">{{ __('Företaget uppfyller villkoren för K2') }}</x-button>
                <a href="{{ route('teams.show', $company) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Uppfyller inte företaget villkoren? Byt till K3 i inställningarna.') }}</a>
            </div>
        </section>
    @endif

    <section class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-3xl bg-ink px-5 py-4 text-white shadow-xs">
            <p class="text-sm text-stone-300">{{ $profit >= 0 ? __('Resultat före skatt') : __('Förlust före skatt') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums">{{ $kr(abs($profit)) }}</p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Checklistan') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ __(':done av :total klara', ['done' => $done, 'total' => count($items)]) }}</p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Status') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight text-ink">{{ $fiscalYear->isOpen() ? __('Öppet') : __('Stängt :date', ['date' => $fiscalYear->closed_at?->toDateString()]) }}</p>
        </div>
    </section>

    <x-ui.card :title="__('1. Checklista')" :description="__('Punkterna som systemet kan se i bokföringen bockas av av sig själva. De andra bekräftar du när de är gjorda eller inte gäller företaget.')">
        <ul class="divide-y divide-stone-200">
            @foreach($items as $item)
                <li wire:key="item-{{ $item['key'] }}" class="flex flex-wrap items-start gap-4 py-3">
                    <span @class([
                        'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                        'bg-ink text-white' => $item['status'] === 'ok',
                        'bg-stone-200 text-gray-600' => $item['status'] === 'na',
                        'bg-accent-100 text-accent-800' => $item['status'] === 'todo',
                        'bg-amber-100 text-amber-900' => $item['status'] === 'warn',
                    ]) aria-hidden="true">{{ match ($item['status']) { 'ok' => '✓', 'na' => '–', default => '!' } }}</span>
                    <div class="min-w-0 flex-1 basis-48">
                        <p class="font-semibold text-ink">{{ $item['label'] }} <span class="sr-only">{{ match ($item['status']) { 'ok' => __('Klar'), 'na' => __('Gäller inte'), 'warn' => __('Kontrollera'), default => __('Att göra') } }}</span></p>
                        <p class="text-sm text-gray-600">{{ $item['detail'] }}</p>
                    </div>
                    @if($item['key'] === 'opening' && $item['confirm'] !== null && $item['status'] === 'todo' && $fiscalYear->isOpen())
                        <form wire:submit="confirmOpening" class="flex w-full flex-wrap items-end gap-2 sm:ml-10" data-opening-reason>
                            <label for="opening_reason" class="block w-full text-sm text-gray-800 sm:w-auto sm:flex-1">
                                {{ __('Skäl till att skillnaden är avsiktlig') }}
                                <input id="opening_reason" type="text" maxlength="1000" wire:model="openingReason" class="mt-1 block w-full rounded-lg border-stone-300 text-sm shadow-xs focus:border-accent-500 focus:ring-accent-500">
                                <span class="mt-1 block text-xs text-gray-600">{{ __('Skriv inte personuppgifter i skälet.') }}</span>
                            </label>
                            <x-button wire:loading.attr="disabled">{{ $item['confirm'] }}</x-button>
                            <a href="{{ route($item['route']) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Gå dit') }}</a>
                        </form>
                    @elseif($item['confirm'] !== null && $fiscalYear->isOpen())
                        <label class="ml-10 flex min-h-11 items-center gap-2 text-sm text-gray-800 sm:ml-0 sm:min-h-0">
                            <input type="checkbox" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500" @checked($item['status'] === 'ok') wire:click="check('{{ $item['key'] }}', $event.target.checked)">
                            {{ $item['confirm'] }}
                        </label>
                    @elseif($item['manual'] && $fiscalYear->isOpen())
                        @if(($item['url'] ?? null) !== null)
                            <a href="{{ $item['url'] }}" class="text-sm font-medium text-accent-700 hover:underline">{{ $item['link_label'] ?? __('Visa reskontran') }}</a>
                        @endif
                        <label class="ml-10 flex min-h-11 items-center gap-2 text-sm text-gray-800 sm:ml-0 sm:min-h-0">
                            <input type="checkbox" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500" @checked($item['status'] === 'ok') wire:click="check('{{ $item['key'] }}', $event.target.checked)">
                            {{ __('Klart eller gäller inte') }}
                        </label>
                    @elseif($item['route'] !== null && in_array($item['status'], ['todo', 'warn'], true))
                        <a href="{{ route($item['route']) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Gå dit') }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    <x-ui.card :title="__('2. Bokslutsverifikationer')" :description="__('Bokförs den sista dagen i räkenskapsåret. Bokför först allt annat, sedan skatten och sist årets resultat.')">
        <div class="space-y-6">
            @if($unpaidEntries->isNotEmpty() || ($unpaid !== null && $unpaid['applies']))
                <div>
                    <p class="font-semibold text-ink">{{ __('Obetalda fakturor vid årets slut') }}</p>
                    @if($unpaidEntries->isNotEmpty())
                        <p class="mt-1 text-sm text-gray-700">{{ __('Bokförda: kundfordringar :receivables och leverantörsskulder :payables. De återförs första dagen i nästa år.', ['receivables' => $kr($unpaidEntries->get('UNPAID_RECEIVABLES')?->amount_ore->ore ?? 0), 'payables' => $kr($unpaidEntries->get('UNPAID_PAYABLES')?->amount_ore->ore ?? 0)]) }}</p>
                    @else
                        <p class="mt-1 text-sm text-gray-700">{{ __('Med kontantmetoden bokförs fakturor när de betalas. Fakturor som är obetalda den :date bokförs den dagen, på samma konton och momskonton som med fakturametoden, och återförs första dagen i nästa år så att betalningen sedan bokförs som vanligt.', ['date' => $fiscalYear->end_date->toDateString()]) }}</p>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead><tr class="text-left text-gray-500"><th class="py-1 pr-4 font-medium">{{ __('Faktura') }}</th><th class="py-1 text-right font-medium">{{ __('Obetalt') }}</th></tr></thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach([...$unpaid['customer'], ...$unpaid['supplier']] as $line)
                                        <tr>
                                            <td class="py-1 pr-4">{{ $line['title'] }}</td>
                                            <td class="py-1 text-right tabular-nums">{{ $kr($line['open']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-sm text-gray-600">{{ __('Momsen hamnar i momsdeklarationen för perioden med årets sista dag om den inte redan är bokförd. Bokför därför det här steget innan du bokför momsen för årets sista period.') }}</p>
                        <x-button type="button" class="mt-3" wire:click="bookUnpaidInvoices" wire:confirm="{{ __('Bokföra de obetalda fakturorna den sista dagen och återföringen första dagen i nästa år?') }}">{{ __('Bokför obetalda fakturor') }}</x-button>
                    @endif
                </div>
            @endif

            @if($revaluationEntry !== null || ($revaluation !== null && $revaluation['applies']))
                <div>
                    <p class="font-semibold text-ink">{{ __('Omvärdering av valutafordringar och valutaskulder') }}</p>
                    @if($revaluationEntry !== null)
                        <p class="mt-1 text-sm text-gray-700">{{ $revaluationEntry->amount_ore->ore >= 0 ? __('Bokförd: kursvinst :amount. Den återförs första dagen i nästa år.', ['amount' => $revaluationEntry->amount_ore->format()]) : __('Bokförd: kursförlust :amount. Den återförs första dagen i nästa år.', ['amount' => $revaluationEntry->amount_ore->negated()->format()]) }}</p>
                    @else
                        <p class="mt-1 text-sm text-gray-700">{{ __('Obetalda fakturor i utländsk valuta värderas till Riksbankens kurs den :date. Skillnaden mot det bokförda bokförs på 3960 eller 7960 och återförs första dagen i nästa år.', ['date' => $fiscalYear->end_date->toDateString()]) }}</p>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead><tr class="text-left text-gray-500"><th class="py-1 pr-4 font-medium">{{ __('Faktura') }}</th><th class="py-1 pr-4 text-right font-medium">{{ __('Obetalt') }}</th><th class="py-1 pr-4 text-right font-medium">{{ __('Bokfört') }}</th><th class="py-1 pr-4 text-right font-medium">{{ __('Till dagens kurs') }}</th><th class="py-1 text-right font-medium">{{ __('Skillnad') }}</th></tr></thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach($revaluation['lines'] as $line)
                                        <tr>
                                            <td class="py-1 pr-4">{{ $line['title'] }}</td>
                                            <td class="py-1 pr-4 text-right tabular-nums">{{ \App\Support\Money\Money::ofOre($line['foreign'])->format(withCurrency: false) }} {{ $line['currency'] }}</td>
                                            <td class="py-1 pr-4 text-right tabular-nums">{{ $kr($line['booked']) }}</td>
                                            <td class="py-1 pr-4 text-right tabular-nums">{{ $kr($line['closing']) }}</td>
                                            <td class="py-1 text-right tabular-nums">{{ $kr($line['difference']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if($unpaid !== null && $unpaid['applies'])
                            <p class="mt-2 text-sm text-accent-800">{{ __('Bokför först de obetalda fakturorna vid årets slut. Sedan kan de omvärderas.') }}</p>
                        @elseif($revaluation['missing'] !== [])
                            <p class="mt-2 text-sm text-accent-800">{{ __('Riksbanken har ingen kurs för :currencies den sista dagen. Bokför omvärderingen för hand och bekräfta det i checklistan.', ['currencies' => implode(', ', $revaluation['missing'])]) }}</p>
                        @elseif(\App\Domain\Closing\CurrencyRevaluation::rows($revaluation['lines']) !== [])
                            <x-button type="button" class="mt-3" wire:click="bookCurrencyRevaluation" wire:confirm="{{ __('Bokföra omvärderingen den sista dagen och återföringen första dagen i nästa år?') }}">{{ __('Bokför omvärderingen') }}</x-button>
                        @endif
                    @endif
                </div>
            @endif

            @if($ownerTaxed)
                <div data-tax-owner-note>
                    <p class="font-semibold text-ink">{{ __('Årets skatt') }}</p>
                    <p class="mt-1 text-sm text-gray-700">{{ __('Enskild firma, handelsbolag och kommanditbolag betalar ingen egen inkomstskatt. Överskottet beskattas hos ägaren eller delägarna i deras egna deklarationer, så ingen skatt bokförs i företaget och ingen verifikation skapas här.') }}</p>
                </div>
            @else
                <div data-tax-step>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold text-ink">{{ __('Årets skatt') }}</p>
                        @if($proposalApplies)<x-ui.badge tone="accent">{{ __('Beta') }}</x-ui.badge>@endif
                    </div>
                    @if($taxEntry !== null)
                        <p class="mt-1 text-sm text-gray-700">{{ $taxEntry->amount_ore->ore === 0 ? __('Ingen skatt för året.') : __(':amount bokfört på 8910 mot 2512.', ['amount' => $taxEntry->amount_ore->format()]) }}</p>
                    @elseif($fiscalYear->isOpen())
                        @php
                            $taxStatus = $taxProposal?->status();
                        @endphp
                        @if($taxProposal !== null)
                            <fieldset class="mt-2" data-tax-status>
                                <legend class="text-sm font-medium text-ink">{{ __('Är företaget ett privatbostadsföretag, till exempel en bostadsrättsförening, eller ett investmentföretag?') }}</legend>
                                <p class="text-xs text-gray-500">{{ __('Ett privatbostadsföretag är en förening eller ett aktiebolag som till klart övervägande del upplåter bostäder åt sina medlemmar eller delägare i hus som det äger (inkomstskattelagen 2 kap. 17 §).') }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach([\App\Domain\Closing\TaxProposal::STATUS_ORDINARY => __('Nej, inget av dem'), \App\Domain\Closing\TaxProposal::STATUS_PRIVATE_HOUSING => __('Ja, privatbostadsföretag'), \App\Domain\Closing\TaxProposal::STATUS_INVESTMENT => __('Ja, investmentföretag')] as $status => $statusLabel)
                                        <button type="button" wire:key="tax-status-{{ $status }}" wire:click="answerTaxStatus('{{ $status }}')" @class([
                                            'rounded-full px-4 py-2 text-sm font-semibold transition',
                                            'bg-ink text-white' => $taxStatus === $status,
                                            'bg-stone-200/60 text-gray-800 hover:bg-stone-200' => $taxStatus !== $status,
                                        ]) aria-pressed="{{ $taxStatus === $status ? 'true' : 'false' }}">{{ $statusLabel }}</button>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                        @if($taxProposal !== null && $taxStatus === \App\Domain\Closing\TaxProposal::STATUS_PRIVATE_HOUSING)
                            <p class="mt-3 text-sm text-gray-700" data-tax-manual-reason>{{ __('Ett privatbostadsföretag tar inte upp inkomster och drar inte av utgifter som hör till fastigheten (inkomstskattelagen 39 kap. 25 §) och får inte sätta av till periodiseringsfond (30 kap. 1 §). NONOX räknar därför inte fram skatten. Bokför den med ett eget belopp nedan.') }}</p>
                        @elseif($taxProposal !== null && $taxStatus === \App\Domain\Closing\TaxProposal::STATUS_INVESTMENT)
                            <p class="mt-3 text-sm text-gray-700" data-tax-manual-reason>{{ __('Ett investmentföretag har egna skatteregler (inkomstskattelagen 39 kap. 14 §) och får inte sätta av till periodiseringsfond (30 kap. 1 §). NONOX räknar därför inte fram skatten. Bokför den med ett eget belopp nedan.') }}</p>
                        @elseif($taxProposal !== null && $taxStatus === null)
                            <p class="mt-3 text-sm text-gray-700">{{ __('Svara på frågan ovan, så räknar NONOX fram ett förslag till årets skatt.') }}</p>
                        @elseif($taxProposal !== null)
                            @php
                                $taxOre = $taxProposal->taxOreOrNull();
                                $rate = $taxProposal->rateBp();
                                $rateEntry = \App\Domain\Closing\CorporateTaxRules::rateEntryFor($fiscalYear);
                                $whole = fn (int $kronor): string => \App\Domain\IncomeTax\IncomeTaxForms::kronor($kronor);
                                $funds = $taxProposal->funds();
                                $fundLocked = $taxProposal->fundBookedByHand();
                                $blockers = $taxProposal->blockers();
                            @endphp
                            <p class="mt-1 text-sm text-gray-700">{{ __('NONOX räknar fram ett förslag till årets inkomstskatt från bokföringen och det du har fyllt i under Inkomstdeklaration. Granska raderna. Ingenting bokförs förrän du bekräftar.') }}</p>
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-sm" data-tax-lines>
                                    <caption class="sr-only">{{ __('Skatteberäkning') }}</caption>
                                    <thead>
                                        <tr class="text-left text-gray-500">
                                            <th scope="col" class="py-1 pr-4 font-medium">{{ __('Rad') }}</th>
                                            <th scope="col" class="py-1 pr-4 font-medium">{{ __('Varifrån') }}</th>
                                            <th scope="col" class="py-1 text-right font-medium">{{ __('Belopp') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-stone-100">
                                        @foreach($taxProposal->lines() as $line)
                                            <tr wire:key="tax-line-{{ $line['key'] }}" @class(['font-semibold text-ink' => $line['total']])>
                                                <td class="py-1.5 pr-4">{{ $line['label'] }}@if($line['field'] !== null) <span class="text-gray-500">({{ __('fält :code', ['code' => $line['field']]) }})</span>@endif</td>
                                                <td class="py-1.5 pr-4 text-gray-600">{{ $line['origin'] }}</td>
                                                <td @class(['py-1.5 text-right tabular-nums', 'text-red-700' => $line['kronor'] < 0])>{{ $whole($line['kronor']) }}</td>
                                            </tr>
                                        @endforeach
                                        <tr class="font-semibold text-ink">
                                            <td class="py-1.5 pr-4">{{ $rate === null ? __('Inkomstskatt') : __('Inkomstskatt :rate %', ['rate' => rtrim(rtrim(\App\Domain\IncomeTax\IncomeTaxForms::percent($rate), '0'), ',')]) }}</td>
                                            <td class="py-1.5 pr-4 font-normal text-gray-600">
                                                {{ __('Inkomstskattelagen 65 kap. 10 §') }}
                                                @if($rateEntry !== null)· <a href="{{ $rateEntry['source_url'] }}" class="underline" target="_blank" rel="noopener">{{ __('Källa') }}</a>@endif
                                            </td>
                                            <td class="py-1.5 text-right tabular-nums" data-tax-amount>{{ $taxOre === null ? '–' : $kr($taxOre) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            @if(! $fundLocked)
                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    @if($funds !== [])
                                        <x-ui.field for="closing_reversal" :label="__('Återföring av periodiseringsfond, kr')" :error="'reversal'" :hint="__('Fonder vid årets början: :funds. Den äldsta återförs först. Fonder som är sex år eller äldre ska återföras i år: :mandatory.', ['funds' => collect($funds)->map(fn (array $fund): string => $fund['account'].($fund['year'] !== null ? ' ('.$fund['year'].')' : '').' '.$whole($fund['kronor']))->implode(', '), 'mandatory' => $whole($taxProposal->mandatoryReversalKronor())])">
                                            <input id="closing_reversal" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model.live.debounce.400ms="reversal" placeholder="{{ $taxProposal->mandatoryReversalKronor() }}">
                                        </x-ui.field>
                                    @endif
                                    <x-ui.field for="closing_allocation" :label="__('Avsättning till periodiseringsfond, kr')" :error="'allocation'" :hint="__('Frivillig. Högst :max, 25 procent av överskottet före avsättningen. Lämna 0 om du inte vill sätta av något.', ['max' => $whole($taxProposal->maxAllocationKronor())])">
                                        <input id="closing_allocation" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model.live.debounce.400ms="allocation" placeholder="0">
                                    </x-ui.field>
                                </div>
                                @if($taxProposal->maxAllocationKronor() > 0)
                                    <button type="button" class="mt-2 text-sm font-semibold text-accent-800 underline" wire:click="useMaxAllocation">{{ __('Använd högsta avsättning') }}</button>
                                @endif
                            @endif

                            @foreach($blockers as $blocker)
                                <p class="mt-3 rounded-xl bg-accent-50 px-3 py-2 text-sm text-accent-900" data-tax-blocker>{{ $blocker }}</p>
                            @endforeach

                            @foreach($taxProposal->warnings() as $warning)
                                <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900" data-tax-warning>{{ $warning }}</p>
                            @endforeach

                            <p class="mt-3 text-xs text-gray-500">{{ __('Beta: reglerna kommer från inkomstskattelagen, Skatteverket och BAS-kontoplanen och är ännu inte granskade av en jurist. Du ansvarar för att beloppen stämmer med deklarationen.') }}</p>

                            @if($blockers === [])
                                <x-button type="button" class="mt-3" wire:click="confirmTaxProposal({{ $taxOre ?? 0 }})" wire:loading.attr="disabled" wire:dirty.attr="disabled" wire:target="reversal,allocation,confirmTaxProposal" wire:confirm="{{ $taxProposal->confirmationText() }}">{{ __('Bekräfta och bokför skatten') }}</x-button>
                            @endif
                        @elseif(! $proposalApplies)
                            <p class="mt-1 text-sm text-gray-700">{{ __('NONOX räknar bara fram skatten för aktiebolag och ekonomiska föreningar. Föreningar och stiftelser har egna skatteregler. Räkna fram skatten och bokför den här.') }}</p>
                        @endif

                        <details class="mt-4" @if(! $proposalApplies || ($taxStatus !== null && $taxStatus !== \App\Domain\Closing\TaxProposal::STATUS_ORDINARY)) open @endif>
                            <summary class="cursor-pointer text-sm font-semibold text-ink">{{ __('Bokför ett eget belopp i stället') }}</summary>
                            <form wire:submit="bookTax" class="mt-2 flex flex-wrap items-end gap-4">
                                <x-ui.field for="closing_tax" class="w-full max-w-md" :label="__('Inkomstskatt, kr')" :hint="$profit > 0 ? __('Räkna fram skatten på det skattemässiga resultatet. Som jämförelse är 20,6 % av resultatet före skatt :amount.', ['amount' => $kr(intdiv($profit * 206 + 500, 1000))]) : __('Ingen vinst, så oftast ingen skatt. Skriv 0.')">
                                    <input id="closing_tax" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="tax">
                                </x-ui.field>
                                <x-button wire:confirm="{{ __('Bokföra beloppet som årets skatt på 8910 mot 2512? En bokförd verifikation kan inte ändras, bara rättas med en ny.') }}">{{ __('Bokför skatten') }}</x-button>
                            </form>
                        </details>
                    @endif
                </div>
            @endif

            <div>
                <p class="font-semibold text-ink">{{ __('Årets resultat') }}</p>
                @if(! $result->hasActivity())
                    <p class="mt-1 text-sm text-gray-700">{{ __('Inget resultat i året.') }}</p>
                @elseif($result->isBooked())
                    <p class="mt-1 text-sm text-gray-700">{{ __('Resultatet efter skatt, :amount, är bokat på 8999 mot :account.', ['amount' => $kr(-$result->afterTaxOre()), 'account' => $resultAccount]) }}</p>
                @elseif($fiscalYear->isOpen())
                    <p class="mt-1 text-sm text-gray-700">{{ __('Kvar att boka: :amount, på 8999 mot :account.', ['amount' => $kr(-$result->unbookedOre()), 'account' => $resultAccount]) }}</p>
                    @if(! $ownerTaxed && $taxEntry === null)
                        <p class="mt-1 text-sm text-gray-500">{{ __('Bokför skatten först.') }}</p>
                    @else
                    <x-button type="button" class="mt-3" wire:click="bookResult" wire:confirm="{{ __('Boka årets resultat? Bokför allt annat i året först.') }}">{{ __('Boka årets resultat') }}</x-button>
                    @endif
                @endif
            </div>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('3. Stäng året')">
        @if(! $fiscalYear->isOpen())
            <p class="text-sm text-gray-700">{{ __('Året är stängt. Inget kan bokföras i det, och de utgående balanserna är ingående balanser i nästa år.') }}</p>
        @else
            <p class="text-sm text-gray-700">{{ __('När året stängs går det inte längre att bokföra i det, bokföringen låses till och med :date och balanserna förs över till nästa år. Det går inte att ångra.', ['date' => $fiscalYear->end_date->toDateString()]) }}</p>
            @if(! $complete)
                <p class="mt-3 text-sm font-medium text-accent-800">{{ __('Bocka av hela checklistan först.') }}</p>
            @elseif(! $canClose)
                <p class="mt-3 text-sm text-gray-600">{{ __('Bara den som får låsa perioder kan stänga året.') }}</p>
            @endif
            <x-danger-button class="mt-4" wire:click="close" wire:confirm="{{ __('Stänga räkenskapsåret :year för gott? Det går inte att ångra.', ['year' => $fiscalYear->label()]) }}" :disabled="! $complete || ! $canClose">{{ __('Stäng räkenskapsåret') }}</x-danger-button>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('4. Resultatdisposition')" :description="$ownerTaxed ? __('Årets resultat förs till eget kapital i början av nästa år.') : __('Efter årsstämman: resultatet förs till balanserat resultat, med den utdelning stämman beslutat.')">
        @if($disposition !== null)
            <p class="text-sm text-gray-700">{{ __('Resultatet :amount är disponerat.', ['amount' => $disposition->amount_ore->format()]) }}@if($disposition->dividend_ore->ore > 0) {{ __('Utdelning :amount på 2898.', ['amount' => $disposition->dividend_ore->format()]) }}@endif</p>
        @elseif($fiscalYear->isOpen())
            <p class="text-sm text-gray-600">{{ __('Stäng året först.') }}</p>
        @elseif($next === null)
            <p class="text-sm text-gray-600">{{ __('Nästa räkenskapsår finns inte.') }}</p>
        @else
            <form wire:submit="dispose" class="flex flex-wrap items-end gap-4">
                <x-ui.field for="disposition_date" :label="$ownerTaxed ? __('Datum') : __('Datum för årsstämman')"><input id="disposition_date" type="date" class="{{ $input }} mt-1" wire:model="dispositionDate"></x-ui.field>
                @unless($ownerTaxed)
                    <x-ui.field for="disposition_dividend" :label="__('Utdelning, kr')" :hint="__('0 om ingen utdelning. Högst det fria egna kapitalet, :free.', ['free' => \App\Support\Money\Money::ofOre(max($freeEquity ?? 0, 0))->format()])"><input id="disposition_dividend" type="text" inputmode="decimal" class="{{ $input }} mt-1 w-48" wire:model="dividend"></x-ui.field>
                @endunless
                <x-button>{{ __('Bokför resultatdispositionen') }}</x-button>
            </form>
        @endif
    </x-ui.card>
</div>
