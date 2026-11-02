@php
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
    $money = fn (?int $ore) => App\Support\Money\Money::ofOre((int) $ore);
@endphp
@if($valuation->lines === [])
    <x-ui.empty-state :title="__('Inget i lager den dagen')">{{ __('Lagret byggs upp av inleveranser och inventeringar.') }}</x-ui.empty-state>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-gray-500">
                    <th class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('Per plats') }}</th>
                    <th class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                    <th class="py-2 pr-4 text-right font-medium">{{ __('Anskaffningsvärde') }}</th>
                    <th class="py-2 pr-4 text-right font-medium">{{ __('Nettoförsäljningsvärde/st') }}</th>
                    <th class="py-2 text-right font-medium">{{ __('Värde') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($valuation->lines as $line)
                    <tr wire:key="valuation-line-{{ $line['article_id'] }}">
                        <td class="py-2 pr-4"><span class="font-semibold tabular-nums">{{ $line['number'] }}</span> {{ $line['name'] }}</td>
                        <td class="py-2 pr-4">
                            @foreach($line['locations'] as $location)
                                <span class="mr-3 inline-block whitespace-nowrap tabular-nums"><span class="text-gray-500">{{ $location['code'] }}</span> {{ $q($location['quantity_milli']) }}</span>
                            @endforeach
                        </td>
                        <td class="py-2 pr-4 text-right tabular-nums whitespace-nowrap">{{ $q($line['quantity_milli']) }} {{ $line['unit'] }}</td>
                        <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($line['cost_value_ore'])" /></td>
                        <td class="py-2 pr-4 text-right">@if($line['net_realisable_unit_ore'] !== null)<x-ui.amount :money="$money($line['net_realisable_unit_ore'])" />@endif</td>
                        <td class="py-2 text-right font-semibold">
                            <x-ui.amount :money="$money($line['value_ore'])" />
                            @if($line['written_down'])<span class="block text-xs font-normal text-accent-700">{{ __('Nedskrivet till nettoförsäljningsvärdet') }}</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-gray-300 font-semibold">
                    <td class="py-2 pr-4" colspan="3">{{ __('Summa') }}</td>
                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($valuation->costValueOre)" /></td>
                    <td></td>
                    <td class="py-2 text-right"><x-ui.amount :money="$money($valuation->valueOre)" /></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
