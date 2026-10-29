@php
    use App\Support\Money\Money;

    $cell = 'w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm';
    $rates = [2500 => '25 %', 1200 => '12 %', 600 => '6 %', 0 => __('0 %')];
    $kr = fn (int $ore): Money => Money::ofOre($ore);
@endphp

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
                        <th class="pb-2 pr-2 font-medium w-28 text-right">{{ __('Á-pris') }}</th>
                        <th class="pb-2 pr-2 font-medium w-20 text-right">{{ __('Rabatt %') }}</th>
                        <th class="pb-2 pr-2 font-medium w-24">{{ __('Moms') }}</th>
                        <th class="pb-2 pr-2 font-medium w-28 text-right">{{ __('Belopp') }}</th>
                        <th class="pb-2 w-8"><span class="sr-only">{{ __('Ta bort') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $index => $row)
                        <tr wire:key="row-{{ $index }}">
                            <td class="py-1 pr-2"><input type="text" list="article-options" class="{{ $cell }}" wire:model.live.blur="rows.{{ $index }}.article" aria-label="{{ __('Artikel, rad :line', ['line' => $index + 1]) }}" autocomplete="off"></td>
                            <td class="py-1 pr-2 min-w-[14rem]"><input type="text" class="{{ $cell }}" wire:model.live.blur="rows.{{ $index }}.description" maxlength="255" aria-label="{{ __('Benämning, rad :line', ['line' => $index + 1]) }}"></td>
                            <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" wire:model.live.blur="rows.{{ $index }}.quantity" aria-label="{{ __('Antal, rad :line', ['line' => $index + 1]) }}"></td>
                            <td class="py-1 pr-2"><input type="text" class="{{ $cell }}" wire:model.live.blur="rows.{{ $index }}.unit" maxlength="10" aria-label="{{ __('Enhet, rad :line', ['line' => $index + 1]) }}"></td>
                            <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" wire:model.live.blur="rows.{{ $index }}.price" aria-label="{{ __('Pris, rad :line', ['line' => $index + 1]) }}"></td>
                            <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} text-right tabular-nums" wire:model.live.blur="rows.{{ $index }}.discount" aria-label="{{ __('Rabatt, rad :line', ['line' => $index + 1]) }}"></td>
                            <td class="py-1 pr-2">
                                <select class="{{ $cell }}" wire:model.live="rows.{{ $index }}.vat_rate" aria-label="{{ __('Moms, rad :line', ['line' => $index + 1]) }}">
                                    @foreach($rates as $rate => $label)
                                        <option value="{{ $rate }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-1 pr-2 text-right whitespace-nowrap" data-row-net>@if(($rowNets[$index] ?? null) !== null)<x-ui.amount :money="$kr($rowNets[$index])" :currency="false" />@endif</td>
                            <td class="py-1 text-center"><button type="button" class="text-gray-400 hover:text-accent-600" wire:click="removeRow({{ $index }})" aria-label="{{ __('Ta bort rad :line', ['line' => $index + 1]) }}">×</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <button type="button" class="mt-3 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="addRow">+ {{ __('Lägg till rad') }}</button>

        @foreach($errors->getMessages() as $key => $messages)
            @if(str_starts_with($key, 'rows'))
                @foreach($messages as $message)
                    <p class="mt-2 text-sm text-accent-700">{{ $message }}</p>
                @endforeach
            @endif
        @endforeach

        <div class="mt-6 flex justify-end">
            <dl class="w-full max-w-xs space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-gray-600">{{ __('Summa exkl. moms') }}</dt><dd><x-ui.amount :money="$kr($totals['net'])" /></dd></div>
                @foreach($totals['vat_by_rate'] as $rate => $group)
                    @continue($group['vat'] === 0)
                    <div class="flex justify-between"><dt class="text-gray-600">{{ __('Moms :rate %', ['rate' => $rate / 100]) }}</dt><dd><x-ui.amount :money="$kr($group['vat'])" /></dd></div>
                @endforeach
                @if($totals['rounding'] !== 0)
                    <div class="flex justify-between"><dt class="text-gray-600">{{ __('Öresavrundning') }}</dt><dd><x-ui.amount :money="$kr($totals['rounding'])" /></dd></div>
                @endif
                <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold"><dt>{{ $negative ? __('Att kreditera') : __('Att betala') }}</dt><dd><x-ui.amount :money="$kr($totals['total'])" /></dd></div>
            </dl>
        </div>
    </x-ui.card>

