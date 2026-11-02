{{--
    The rows of an invoice, quote or order. They live in the browser (Alpine,
    resources/js/sales-rows.js) until the document is saved, so stepping
    through the fields is never slowed down or overwritten by the server.
    The server calculates everything again when the document is saved.
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
        'price' => $article->price_ore->ore,
        'vat_rate' => $article->vat_rate,
        'account' => $article->account?->number,
    ]]);
@endphp

<div x-data="salesRows($wire.entangle('rows'), @js($articleMap), @js($negative), {{ isset($reverseCharge) ? "() => \$wire.reverseCharge || (\$wire.foreignSale !== '' && \$wire.foreignSale !== 'EU_CONSUMER_OSS')" : 'false' }}, {{ isset($withCurrency) ? '() => $wire.currency' : "() => 'SEK'" }}, @js($vatRules ?? []))">
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
                        <th class="pb-2 pr-2 font-medium w-16">{{ __('Enhet') }}</th>
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
                                <input type="text" list="article-options" class="{{ $cell }}" x-model="row.article" x-on:change="fillArticle(index)" x-on:blur="fillArticle(index)" data-row-article :aria-label="'{{ __('Artikel, rad') }} ' + (index + 1)" autocomplete="off">
                            </td>
                            <td class="{{ $field }} col-span-6 sm:col-span-4 sm:pr-10 lg:min-w-56">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Benämning') }}</span>
                                <input type="text" class="{{ $cell }}" x-model="row.description" maxlength="255" :aria-label="'{{ __('Benämning, rad') }} ' + (index + 1)">
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Antal') }}</span>
                                <input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.quantity" :aria-label="'{{ __('Antal, rad') }} ' + (index + 1)">
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Enhet') }}</span>
                                <input type="text" class="{{ $cell }}" x-model="row.unit" maxlength="10" :aria-label="'{{ __('Enhet, rad') }} ' + (index + 1)">
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('À-pris') }}</span>
                                <input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.price" :aria-label="'{{ __('Pris, rad') }} ' + (index + 1)">
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Rabatt %') }}</span>
                                <input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.discount" :aria-label="'{{ __('Rabatt, rad') }} ' + (index + 1)">
                            </td>
                            <td class="{{ $field }} col-span-3 sm:col-span-2">
                                <span class="{{ $label }}" aria-hidden="true">{{ __('Moms') }}</span>
                                <select class="{{ $cell }}" x-model="row.vat_rate" :aria-label="'{{ __('Moms, rad') }} ' + (index + 1)">
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
        <button type="button" class="mt-3 inline-flex min-h-11 items-center text-sm font-medium text-accent-600 hover:text-accent-700 lg:inline lg:min-h-0" x-on:click="addRow()">+ {{ __('Lägg till rad') }}</button>

        @foreach($errors->getMessages() as $key => $messages)
            @if(str_starts_with($key, 'rows'))
                @foreach($messages as $message)
                    <p class="mt-2 text-sm text-accent-700" role="alert">{{ $message }}</p>
                @endforeach
            @endif
        @endforeach

        <template x-if="vatWarnings.length > 0">
            <div class="mt-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status" aria-live="polite" data-vat-warning>
                <template x-for="warning in vatWarnings" :key="warning.line">
                    <p x-text="@js(__('Rad :line: konto :account är för försäljning med :expected moms, men raden har :rate. Momsdeklarationen följer momsen, så kontrollera kontot på artikeln eller raden.')).replace(':line', warning.line).replace(':account', warning.account).replace(':expected', warning.rates.map((rate) => percent(rate)).join(', ')).replace(':rate', percent(warning.rate))"></p>
                </template>
            </div>
        </template>

        <div class="mt-6 flex justify-end">
            <dl class="w-full max-w-xs space-y-1 text-sm tabular-nums">
                <div class="flex justify-between"><dt class="text-gray-600">{{ __('Summa exkl. moms') }}</dt><dd x-text="money(totals.net)"></dd></div>
                <template x-for="group in totals.vatByRate.filter((group) => group.vat !== 0)" :key="group.rate">
                    <div class="flex justify-between"><dt class="text-gray-600" x-text="'{{ __('Moms') }} ' + (group.rate / 100) + ' %'"></dt><dd x-text="money(group.vat)"></dd></div>
                </template>
                <div class="flex justify-between" x-show="totals.rounding !== 0"><dt class="text-gray-600">{{ __('Öresavrundning') }}</dt><dd x-text="money(totals.rounding)"></dd></div>
                <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>{{ $negative ? __('Att kreditera') : __('Att betala') }}</dt><dd x-text="money(totals.total)"></dd></div>
            </dl>
        </div>
    </x-ui.card>
</div>
