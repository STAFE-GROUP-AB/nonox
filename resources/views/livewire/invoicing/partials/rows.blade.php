{{--
    The rows of an invoice, quote or order. They live in the browser (Alpine,
    resources/js/sales-rows.js) until the document is saved, so stepping
    through the fields is never slowed down or overwritten by the server.
    The server calculates everything again when the document is saved.
--}}
@php
    $cell = 'w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm';
    $rates = [2500 => '25 %', 1200 => '12 %', 600 => '6 %', 0 => __('0 %')];
    $articleMap = $articles->mapWithKeys(fn ($article) => [$article->number => [
        'name' => $article->name,
        'unit' => $article->unit,
        'price' => $article->price_ore->ore,
        'vat_rate' => $article->vat_rate,
    ]]);
@endphp

<div x-data="salesRows($wire.entangle('rows'), @js($articleMap), @js($negative), {{ isset($reverseCharge) ? "\$wire.entangle('reverseCharge')" : 'false' }})">
    <datalist id="article-options">
        @foreach($articles as $article)
            <option value="{{ $article->number }}">{{ $article->name }}</option>
        @endforeach
    </datalist>

    <x-ui.card :title="__('Rader')" :description="__('Skriv ett artikelnummer för att hämta artikeln, eller skriv en egen rad. Priser är utan moms.')">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-2 pr-2 font-medium w-28">{{ __('Artikel') }}</th>
                        <th class="pb-2 pr-2 font-medium">{{ __('Benämning') }}</th>
                        <th class="pb-2 pr-2 font-medium w-20 text-right">{{ __('Antal') }}</th>
                        <th class="pb-2 pr-2 font-medium w-16">{{ __('Enhet') }}</th>
                        <th class="pb-2 pr-2 font-medium w-28 text-right">{{ __('À-pris') }}</th>
                        <th class="pb-2 pr-2 font-medium w-20 text-right">{{ __('Rabatt %') }}</th>
                        <th class="pb-2 pr-2 font-medium w-24">{{ __('Moms') }}</th>
                        <th class="pb-2 pr-2 font-medium w-28 text-right">{{ __('Belopp') }}</th>
                        <th class="pb-2 w-8"><span class="sr-only">{{ __('Ta bort') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, index) in rows" :key="index">
                        <tr>
                            <td class="py-1 pr-2"><input type="text" list="article-options" class="{{ $cell }}" x-model="row.article" x-on:change="fillArticle(index)" x-on:blur="fillArticle(index)" data-row-article :aria-label="'{{ __('Artikel, rad') }} ' + (index + 1)" autocomplete="off"></td>
                            <td class="py-1 pr-2 min-w-[14rem]"><input type="text" class="{{ $cell }}" x-model="row.description" maxlength="255" :aria-label="'{{ __('Benämning, rad') }} ' + (index + 1)"></td>
                            <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.quantity" :aria-label="'{{ __('Antal, rad') }} ' + (index + 1)"></td>
                            <td class="py-1 pr-2"><input type="text" class="{{ $cell }}" x-model="row.unit" maxlength="10" :aria-label="'{{ __('Enhet, rad') }} ' + (index + 1)"></td>
                            <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.price" :aria-label="'{{ __('Pris, rad') }} ' + (index + 1)"></td>
                            <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" x-model="row.discount" :aria-label="'{{ __('Rabatt, rad') }} ' + (index + 1)"></td>
                            <td class="py-1 pr-2">
                                <select class="{{ $cell }}" x-model="row.vat_rate" :aria-label="'{{ __('Moms, rad') }} ' + (index + 1)">
                                    @foreach($rates as $rate => $label)
                                        <option value="{{ $rate }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-1 pr-2 text-right whitespace-nowrap tabular-nums" x-text="net(row)"></td>
                            <td class="py-1 text-center"><button type="button" class="text-gray-400 hover:text-accent-600" x-on:click="removeRow(index)" :aria-label="'{{ __('Ta bort rad') }} ' + (index + 1)">×</button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <button type="button" class="mt-3 text-sm font-medium text-accent-600 hover:text-accent-700" x-on:click="addRow()">+ {{ __('Lägg till rad') }}</button>

        @foreach($errors->getMessages() as $key => $messages)
            @if(str_starts_with($key, 'rows'))
                @foreach($messages as $message)
                    <p class="mt-2 text-sm text-accent-700">{{ $message }}</p>
                @endforeach
            @endif
        @endforeach

        <div class="mt-6 flex justify-end">
            <dl class="w-full max-w-xs space-y-1 text-sm tabular-nums">
                <div class="flex justify-between"><dt class="text-gray-600">{{ __('Summa exkl. moms') }}</dt><dd x-text="format(totals.net) + ' kr'"></dd></div>
                <template x-for="group in totals.vatByRate.filter((group) => group.vat !== 0)" :key="group.rate">
                    <div class="flex justify-between"><dt class="text-gray-600" x-text="'{{ __('Moms') }} ' + (group.rate / 100) + ' %'"></dt><dd x-text="format(group.vat) + ' kr'"></dd></div>
                </template>
                <div class="flex justify-between" x-show="totals.rounding !== 0"><dt class="text-gray-600">{{ __('Öresavrundning') }}</dt><dd x-text="format(totals.rounding) + ' kr'"></dd></div>
                <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>{{ $negative ? __('Att kreditera') : __('Att betala') }}</dt><dd x-text="format(totals.total) + ' kr'"></dd></div>
            </dl>
        </div>
    </x-ui.card>
</div>
