{{--
    The rows of an invoice, quote or order. They live in the browser (Alpine,
    resources/js/sales-rows.js) until the document is saved, so stepping
    through the fields is never slowed down or overwritten by the server.
    The server calculates everything again when the document is saved.
    The unit is chosen from the company's unit register (ADR-071 §12.9).
    Cost centre and project (ADR-081 gap 6) show when the company has any.
    Prices (ADR-082 P3): an editor that prices its rows gives the browser a
    price table; each article row shows where its price came from, "Varför
    detta pris?" explains it, and "Uppdatera priser" shows a diff first.
--}}
@php
    $cell = 'w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm max-lg:min-h-11';
    // Below lg a row is a card: a six-column grid of labelled fields.
    $row = 'relative grid grid-cols-6 gap-x-3 gap-y-3 rounded-2xl p-4 ring-1 ring-gray-200 lg:table-row lg:p-0 lg:ring-0';
    $field = 'block lg:table-cell lg:py-1 lg:pr-2';
    $label = 'mb-1 block text-xs font-medium text-gray-500 lg:hidden';
    $rates = [2500 => '25 %', 1200 => '12 %', 600 => '6 %', 0 => __('0 %')];
    $articleMap = $articles->mapWithKeys(fn ($article) => [$article->number => [
        'name' => $article->name,
        'unit' => $article->unit,
        'unit_id' => $article->sales_unit_id ?? $article->stock_unit_id,
        'price' => $article->price_ore->ore,
        'vat_rate' => $article->vat_rate,
        'account' => $article->account?->number,
        'cost_center' => (string) $article->defaultCostCenter?->code,
        'project' => (string) $article->defaultProject?->code,
    ]]);
    $dimensions = $rowDimensions ?? ['show' => false, 'costCenters' => [], 'projects' => [], 'customers' => []];
    $pricing ??= ['enabled' => false, 'markers' => false, 'canExplain' => false, 'labels' => [], 'warnings' => []];
    $pricingData = [...$pricing, 'watch' => $priceWatch ?? [], 'margin' => $margin ?? false, 'marginInitial' => ($margin ?? false) ? ($marginAtOpen ?? []) : []];
    $dimensionData = [
        'projectCostCenters' => collect($dimensions['projects'])->map(fn (array $project): string => $project['cost_center'])->all(),
        'customers' => $dimensions['customers'],
    ];
@endphp

<div x-data="salesRows($wire.entangle('rows'), @js($articleMap), @js($negative), {{ isset($reverseCharge) ? "() => \$wire.reverseCharge || (\$wire.foreignSale !== '' && \$wire.foreignSale !== 'EU_CONSUMER_OSS')" : 'false' }}, {{ isset($withCurrency) ? '() => $wire.currency' : "() => 'SEK'" }}, @js($vatRules ?? []), @js((string) $defaultUnitId), @js($dimensionData), {{ isset($withCustomer) ? '() => $wire.customerId' : "() => ''" }}, @js($pricingData))">
    <datalist id="article-options">
        @foreach($articles as $article)
            <option value="{{ $article->number }}">{{ $article->name }}</option>
        @endforeach
    </datalist>

    <x-ui.card :title="__('Rader')" :description="__('Skriv ett artikelnummer för att hämta artikeln, eller skriv en egen rad. Priser är utan moms.')">
        {{-- A table from lg up; below that every row is a card with labelled fields. --}}
        <div class="lg:overflow-x-auto">
            <table class="block w-full text-sm lg:table">
                <thead class="hidden lg:table-header-group">
                    <tr class="text-left text-gray-500">
                        <th class="pb-2 pr-2 font-medium w-28">{{ __('Artikel') }}</th>
                        <th class="pb-2 pr-2 font-medium">{{ __('Benämning') }}</th>
                        <th class="pb-2 pr-2 font-medium w-20 text-right">{{ __('Antal') }}</th>
                        <th class="pb-2 pr-2 font-medium w-24">{{ __('Enhet') }}</th>
                        <th class="pb-2 pr-2 font-medium w-28 text-right">{{ __('À-pris') }}</th>
                        <th class="pb-2 pr-2 font-medium w-20 text-right">{{ __('Rabatt %') }}</th>
                        <th class="pb-2 pr-2 font-medium w-24">{{ __('Moms') }}</th>
                        <th class="pb-2 pr-2 font-medium w-28 text-right">{{ __('Belopp') }}</th>
                        @if($labour ?? false)
                            <th class="pb-2 pr-2 font-medium w-16 text-center">{{ __('Arbete') }}</th>
                        @endif
                        <th class="pb-2 w-8"><span class="sr-only">{{ __('Ta bort') }}</span></th>
                    </tr>
                </thead>
                <tbody class="flex flex-col gap-3 lg:table-row-group" data-row-cards>
                    <template x-for="(row, index) in rows" :key="index">
                        <tr class="{{ $row }}">
                            <td class="{{ $field }} col-span-6 pr-10 sm:col-span-2 sm:pr-0">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Artikel') }}</span>
                                <input type="text" list="article-options" class="{{ $cell }}" x-model="row.article" x-on:focus="rememberArticle(index)" x-on:change="fillArticle(index)" x-on:blur="fillArticle(index)" data-row-article :aria-label="'{{ __('Artikel, rad') }} ' + (index + 1)" autocomplete="off">
                            </td>
                            <td class="{{ $field }} col-span-6 sm:col-span-4 sm:pr-10 lg:min-w-56">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Benämning') }}</span>
                                <input type="text" class="{{ $cell }}" x-model="row.description" maxlength="255" :aria-label="'{{ __('Benämning, rad') }} ' + (index + 1)">
                                @if($dimensions['show'])
                                    {{-- Cost centre and project under the description, so the row keeps its columns (ADR-081 gap 6). --}}
                                    <div class="mt-1 grid grid-cols-2 gap-2">
                                        <label class="block">
                                            <span class="sr-only">{{ __('Kostnadsställe') }}</span>
                                            <select class="{{ $cell }} text-xs text-gray-700" x-model="row.cost_center" x-effect="row.cost_center; $nextTick(() => $el.title = $el.options[$el.selectedIndex]?.text ?? '')" :aria-label="'{{ __('Kostnadsställe, rad') }} ' + (index + 1)" data-row-cost-center>
                                                <option value="">{{ __('Kostnadsställe') }}: {{ __('Inget') }}</option>
                                                @foreach($dimensions['costCenters'] as $code => $costCenterLabel)
                                                    <option value="{{ $code }}">{{ $costCenterLabel }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="sr-only">{{ __('Projekt') }}</span>
                                            <select class="{{ $cell }} text-xs text-gray-700" x-model="row.project" x-on:change="fillProject(index)" x-effect="row.project; $nextTick(() => $el.title = $el.options[$el.selectedIndex]?.text ?? '')" :aria-label="'{{ __('Projekt, rad') }} ' + (index + 1)" data-row-project>
                                                <option value="">{{ __('Projekt') }}: {{ __('Inget') }}</option>
                                                @foreach($dimensions['projects'] as $code => $project)
                                                    <option value="{{ $code }}">{{ $project['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                @endif
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Antal') }}</span>
                                <input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.quantity" x-on:change="repriceRow(index)" :aria-label="'{{ __('Antal, rad') }} ' + (index + 1)">
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Enhet') }}</span>
                                <select class="{{ $cell }}" x-model="row.unit_id" x-on:change="repriceRow(index)" :aria-label="'{{ __('Enhet, rad') }} ' + (index + 1)">
                                    @foreach($units as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->code }}{{ $unit->isArchived() ? ' ('.__('arkiverad').')' : '' }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('À-pris') }}</span>
                                <input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.price" x-on:input="markManual(index)" :aria-label="'{{ __('Pris, rad') }} ' + (index + 1)">
                                @if($pricing['enabled'])
                                    {{-- Where the price came from (ADR-082 P3): a marker that opens "Varför detta pris?". --}}
                                    <div class="mt-1 flex flex-wrap justify-end gap-1" x-show="row.article && ((markers && sourceLabel(row.price_source) !== '') || row.price === '' || priceWarnings(row).length > 0)" x-cloak>
                                        <template x-if="markers && sourceLabel(row.price_source) !== '' && canExplain">
                                            <button type="button" data-price-marker :data-price-source="row.price_source" class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset" :class="row.price_source === 'MANUAL' ? 'bg-gray-50 text-gray-700 ring-gray-300' : 'bg-accent-50 text-accent-800 ring-accent-200'" x-text="sourceLabel(row.price_source)" x-on:click="explain(index, $el)" title="{{ __('Varför detta pris?') }}" :aria-label="sourceLabel(row.price_source) + '. ' + @js(__('Varför detta pris? Rad')) + ' ' + (index + 1)"></button>
                                        </template>
                                        <template x-if="markers && sourceLabel(row.price_source) !== '' && ! canExplain">
                                            <span data-price-marker :data-price-source="row.price_source" class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-700 ring-gray-300" x-text="sourceLabel(row.price_source)"></span>
                                        </template>
                                        <p class="w-full text-right text-xs text-gray-500" x-show="row.price === ''">{{ __('Priset hämtas när du sparar.') }}</p>
                                        <template x-for="warning in priceWarnings(row)" :key="warning">
                                            <p class="w-full text-right text-xs text-amber-800" data-price-warning x-text="warningText(warning)"></p>
                                        </template>
                                    </div>
                                @endif
                                @if($margin ?? false)
                                    {{-- Under cost or under the floor (ADR-082 §9): a warning on the row, never a block here. --}}
                                    <p class="mt-1 text-right text-xs" :class="marginRow(index)?.below_cost ? 'text-red-700' : 'text-amber-800'" x-show="marginRow(index)" x-cloak role="status" data-margin-warning x-text="marginRow(index)?.text"></p>
                                @endif
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Rabatt %') }}</span>
                                <input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.discount" :aria-label="'{{ __('Rabatt, rad') }} ' + (index + 1)">
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Moms') }}</span>
                                <select class="{{ $cell }}" x-model="row.vat_rate" x-on:change="repriceRow(index, 'vat')" :aria-label="'{{ __('Moms, rad') }} ' + (index + 1)">
                                    @foreach($rates as $rate => $rateLabel)
                                        <option value="{{ $rate }}">{{ $rateLabel }}</option>
                                    @endforeach
                                </select>
                                @if($exemptions ?? false)
                                    {{-- A row sold in Sweden at 0 % says why on the invoice (ML 17 kap. 24 § p. 13). --}}
                                    <select class="{{ $cell }} mt-1" x-show="String(row.vat_rate) === '0' && ! (typeof vatFree === 'function' ? vatFree() : vatFree)" x-model="row.vat_exemption" :aria-label="'{{ __('Skäl till 0 % moms, rad') }} ' + (index + 1)">
                                        @foreach(App\Domain\Invoicing\VatExemptionReason::cases() as $reason)
                                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                            <td class="order-last col-span-6 flex items-baseline justify-between gap-3 border-t border-gray-100 pt-3 font-semibold whitespace-nowrap tabular-nums lg:table-cell lg:border-0 lg:py-1 lg:pr-2 lg:text-right lg:font-normal">
                                <span class="text-xs font-medium text-gray-500 lg:hidden" aria-hidden="true">{{ __('Belopp') }}</span>
                                <span x-text="net(row)"></span>
                            </td>
                            @if($labour ?? false)
                                <td class="{{ $field }} col-span-3 self-end sm:col-span-2 lg:text-center">
                                    <label class="inline-flex min-h-11 items-center gap-2 text-sm text-gray-700 lg:min-h-0">
                                        <input type="checkbox" class="rounded-sm border-gray-300 text-ink focus:ring-accent-500" x-model="row.labour" :aria-label="'{{ __('Arbetskostnad, rad') }} ' + (index + 1)">
                                        <span class="lg:hidden" aria-hidden="true">{{ __('Arbetskostnad') }}</span>
                                    </label>
                                </td>
                            @endif
                            <td class="absolute top-2 right-2 lg:static lg:table-cell lg:py-1 lg:text-center">
                                <button type="button" class="inline-flex size-11 items-center justify-center rounded-full text-xl text-gray-400 hover:text-accent-600 lg:size-8 lg:text-sm" x-on:click="removeRow(index)" :aria-label="'{{ __('Ta bort rad') }} ' + (index + 1)">×</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
            <button type="button" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700 lg:inline lg:min-h-0" x-on:click="addRow()">+ {{ __('Lägg till rad') }}</button>
            @if($pricing['enabled'])
                <button type="button" class="inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700 lg:inline lg:min-h-0" wire:click="previewPrices" x-on:click="rememberFocus($el)" wire:loading.attr="disabled" data-update-prices>{{ __('Uppdatera priser') }}</button>
            @endif
            @if($canManageUnits)
                <a href="{{ route('invoicing.units') }}" class="text-sm font-medium text-accent-700 underline">{{ __('Hantera enheter') }}</a>
            @endif
        </div>

        @if($pricing['enabled'])
            <div class="mt-3 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-800" role="status" x-show="pricesStale" x-cloak>
                {{ __('Kund, datum eller valuta har ändrats. Priserna på raderna ändras inte av sig själva.') }}
                <button type="button" class="ml-1 font-medium text-accent-700 underline" wire:click="previewPrices" x-on:click="pricesStale = false; rememberFocus($el)">{{ __('Uppdatera priser') }}</button>
            </div>
        @endif

        @if(($priceDiff ?? null) !== null)
            {{-- "Uppdatera priser" shows what would change before anything changes (ADR-082 §6). --}}
            <div class="mt-4 rounded-xl p-4 ring-1 ring-accent-200" role="region" aria-labelledby="price-diff-title" tabindex="-1" x-init="$nextTick(() => $el.focus())" data-price-diff>
                <h3 id="price-diff-title" class="text-sm font-semibold text-gray-900">{{ __('Dagens priser') }}</h3>
                <p class="mt-1 text-sm text-gray-600">{{ __('De här raderna får ett nytt pris. Priser som är skrivna för hand ändras inte.') }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="pb-1 pr-3 font-medium">{{ __('Rad') }}</th>
                                <th class="pb-1 pr-3 font-medium">{{ __('Benämning') }}</th>
                                <th class="pb-1 pr-3 text-right font-medium">{{ __('Nu') }}</th>
                                <th class="pb-1 pr-3 text-right font-medium">{{ __('Nytt pris') }}</th>
                                <th class="pb-1 font-medium">{{ __('Skäl') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($priceDiff as $change)
                                <tr class="border-t border-gray-100">
                                    <td class="py-1 pr-3 tabular-nums">{{ $change['line'] }}</td>
                                    <td class="py-1 pr-3">{{ $change['description'] }}</td>
                                    <td class="py-1 pr-3 text-right tabular-nums whitespace-nowrap">{{ $change['old'] }}</td>
                                    <td class="py-1 pr-3 text-right font-semibold tabular-nums whitespace-nowrap">{{ $change['new'] }}</td>
                                    <td class="py-1">{{ $change['source'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <x-button type="button" wire:click="applyPrices" x-on:click="returnFocusSoon()" wire:loading.attr="disabled">{{ count($priceDiff) === 1 ? __('Använd det nya priset') : __('Använd de :count nya priserna', ['count' => count($priceDiff)]) }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelPriceUpdate" x-on:click="returnFocusSoon()">{{ __('Avbryt') }}</button>
                </div>
            </div>
        @endif

        @if(($priceExplanation ?? null) !== null)
            {{-- "Varför detta pris?": the row's explanation, names looked up when shown (ADR-082 §5). --}}
            <div class="mt-4 rounded-xl p-4 ring-1 ring-gray-200" role="region" aria-labelledby="price-explanation-title" tabindex="-1" x-init="$nextTick(() => $el.focus())" data-price-explanation>
                <div class="flex items-start justify-between gap-3">
                    <h3 id="price-explanation-title" class="text-sm font-semibold text-gray-900">{{ __('Varför detta pris?') }} · {{ __('Rad :line', ['line' => $priceExplanation['line']]) }}@if($priceExplanation['description'] !== '') · {{ $priceExplanation['description'] }}@endif</h3>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="closePriceExplanation" x-on:click="returnFocusSoon()">{{ __('Stäng') }}</button>
                </div>
                <p class="mt-2 text-sm text-gray-900"><span class="font-medium">{{ $priceExplanation['source'] }}.</span> {{ $priceExplanation['summary'] }}</p>
                @foreach($priceExplanation['details'] as $detail)
                    <p class="mt-1 text-sm text-gray-700">{{ $detail }}</p>
                @endforeach
                @foreach($priceExplanation['warnings'] as $warning)
                    <p class="mt-1 text-sm text-amber-800">{{ $warning }}</p>
                @endforeach
                @if($priceExplanation['considered'] !== [])
                    <p class="mt-3 text-xs font-medium text-gray-500">{{ __('Andra priser som prövades') }}</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-gray-700">
                        @foreach($priceExplanation['considered'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @foreach($errors->getMessages() as $key => $messages)
            @if(str_starts_with($key, 'rows'))
                @foreach($messages as $message)
                    <p class="mt-2 text-sm text-accent-700" role="alert">{{ $message }}</p>
                @endforeach
            @endif
        @endforeach

        @if($margin ?? false)
            <div class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status" aria-live="polite" x-show="margin.banner !== ''" x-cloak data-margin-banner x-text="margin.banner"></div>
        @endif

        <template x-if="vatWarnings.length > 0">
            <div class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status" aria-live="polite" data-vat-warning>
                <template x-for="warning in vatWarnings" :key="warning.line">
                    <p x-text="@js(__('Rad :line: konto :account är för försäljning med :expected moms, men raden har :rate. Momsdeklarationen följer momsen, så kontrollera kontot på artikeln eller raden.')).replace(':line', warning.line).replace(':account', warning.account).replace(':expected', warning.rates.map((rate) => percent(rate)).join(', ')).replace(':rate', percent(warning.rate))"></p>
                </template>
            </div>
        </template>

        <div class="mt-6 flex flex-col items-end gap-2">
            <dl class="w-full max-w-xs space-y-1 text-sm tabular-nums">
                <div class="flex justify-between"><dt class="text-gray-600">{{ __('Summa exkl. moms') }}</dt><dd x-text="money(totals.net)"></dd></div>
                <template x-for="group in totals.vatByRate.filter((group) => group.vat !== 0)" :key="group.rate">
                    <div class="flex justify-between"><dt class="text-gray-600" x-text="'{{ __('Moms') }} ' + (group.rate / 100) + ' %'"></dt><dd x-text="money(group.vat)"></dd></div>
                </template>
                <div class="flex justify-between" x-show="totals.rounding !== 0"><dt class="text-gray-600">{{ __('Öresavrundning') }}</dt><dd x-text="money(totals.rounding)"></dd></div>
                <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>{{ $negative ? __('Att kreditera') : __('Att betala') }}</dt><dd x-text="money(totals.total)"></dd></div>
                @if($margin ?? false)
                    {{-- Internal: only for prices:view-cost, never on the customer's PDF (ADR-082 §9). --}}
                    <template x-if="margin.totals">
                        <div class="space-y-1 border-t border-gray-100 pt-2 text-gray-700" data-margin-totals>
                            <div class="flex justify-between"><dt>{{ __('Täckningsbidrag') }}</dt><dd x-text="margin.totals.contribution"></dd></div>
                            <div class="flex justify-between"><dt>{{ __('Täckningsgrad') }}</dt><dd x-text="margin.totals.margin"></dd></div>
                        </div>
                    </template>
                @endif
            </dl>
            @if($margin ?? false)
                <p class="w-full max-w-xs text-xs text-gray-500" x-show="margin.totals && margin.totals.uncosted > 0" x-cloak x-text="@js(__(':count rader saknar känd kostnad och räknas inte med.')).replace(':count', margin.totals?.uncosted ?? 0)"></p>
                <p class="w-full max-w-xs text-xs text-gray-500" x-show="margin.note !== ''" x-cloak x-text="margin.note"></p>
            @endif
        </div>
    </x-ui.card>
</div>
