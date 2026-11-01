@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $kr = fn (int $ore): string => $ore === 0 ? '' : number_format(App\Domain\Vat\EuSalesList::kronor($ore), 0, ',', "\u{a0}");
    $totals = $list->totals();
@endphp
<div class="space-y-8">
    <x-ui.card :title="__('Periodisk sammanställning')" :description="__('Försäljning utan moms till företag i andra EU-länder, per köpare. Lämnas till Skatteverket senast den 25:e månaden efter perioden, som fil i e-tjänsten Filöverföring.')">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="eu_period" :label="__('Period')">
                <select id="eu_period" class="{{ $input }} mt-1" wire:model.live="period">
                    @foreach($periods as $key => $label)
                        <option value="{{ $key }}" @selected($key === $selected)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>

        @if($list->rows === [])
            <x-ui.empty-state class="mt-6" :title="__('Ingen försäljning att redovisa')">
                {{ __('Fakturor till EU-företag markerade som varor, tjänster eller trepartshandel hamnar här när de är skapade.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-sm tabular-nums">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="pb-2 pr-4 font-medium">{{ __('Köparens momsreg.nr') }}</th>
                            <th class="pb-2 pr-4 text-right font-medium">{{ __('Varor') }}</th>
                            <th class="pb-2 pr-4 text-right font-medium">{{ __('Trepartshandel') }}</th>
                            <th class="pb-2 text-right font-medium">{{ __('Tjänster') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($list->rows as $buyer => $row)
                            <tr wire:key="buyer-{{ $buyer }}">
                                <td class="py-2 pr-4 font-medium text-gray-900">{{ $buyer }}</td>
                                <td class="py-2 pr-4 text-right">{{ $kr($row['goods']) }}</td>
                                <td class="py-2 pr-4 text-right">{{ $kr($row['triangular']) }}</td>
                                <td class="py-2 text-right">{{ $kr($row['services']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold text-gray-900">
                            <td class="pt-3 pr-4">{{ __('Summa, kronor') }}</td>
                            <td class="pt-3 pr-4 text-right">{{ $kr($totals['goods']) }}</td>
                            <td class="pt-3 pr-4 text-right">{{ $kr($totals['triangular']) }}</td>
                            <td class="pt-3 text-right">{{ $kr($totals['services']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('Summorna ska stämma med rutorna 35, 38 och 39 i momsdeklarationen för samma period.') }}</p>

            <form wire:submit="download" class="mt-6 grid gap-4 border-t border-gray-100 pt-6 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field for="eu_contact" :label="__('Ansvarig person')" error="contactName">
                    <input id="eu_contact" type="text" class="{{ $input }} mt-1" wire:model="contactName" maxlength="35">
                </x-ui.field>
                <x-ui.field for="eu_phone" :label="__('Telefon')" error="phone">
                    <input id="eu_phone" type="text" class="{{ $input }} mt-1" wire:model="phone" maxlength="17" placeholder="08-123456">
                </x-ui.field>
                <x-ui.field for="eu_email" :label="__('E-post (frivillig)')" error="email">
                    <input id="eu_email" type="email" class="{{ $input }} mt-1" wire:model="email">
                </x-ui.field>
                <div class="flex items-end">
                    <x-button wire:loading.attr="disabled" :disabled="! $hasVatNumber">{{ __('Ladda ner fil') }}</x-button>
                </div>
                @unless($hasVatNumber)
                    <p class="text-sm text-accent-700 sm:col-span-2 lg:col-span-4">{{ __('Fyll i företagets momsregistreringsnummer under Företagsuppgifter för att kunna skapa filen.') }}</p>
                @endunless
            </form>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('OSS, försäljning till privatpersoner i andra EU-länder')" :description="__('Underlag för OSS-deklarationen per kvartal: försäljning och moms per land och momssats. Deklarationen lämnas i Skatteverkets e-tjänst i euro, senast sista dagen månaden efter kvartalet.')">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="oss_quarter" :label="__('Kvartal')">
                <select id="oss_quarter" class="{{ $input }} mt-1" wire:model.live="ossQuarter">
                    @foreach($quarters as $key => $label)
                        <option value="{{ $key }}" @selected($key === $selectedOss)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        </div>

        @if($oss->rows === [])
            <x-ui.empty-state class="mt-6" :title="__('Ingen OSS-försäljning')">
                {{ __('Fakturor till privatpersoner i andra EU-länder, markerade för OSS, hamnar här.') }}
            </x-ui.empty-state>
        @else
            @php($ossTotals = $oss->totals())
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-sm tabular-nums">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="pb-2 pr-4 font-medium">{{ __('Land') }}</th>
                            <th class="pb-2 pr-4 text-right font-medium">{{ __('Momssats') }}</th>
                            <th class="pb-2 pr-4 text-right font-medium">{{ __('Försäljning exkl. moms') }}</th>
                            <th class="pb-2 text-right font-medium">{{ __('Moms') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($oss->rows as $row)
                            <tr wire:key="oss-{{ $row['country'] }}-{{ $row['rate'] }}">
                                <td class="py-2 pr-4 font-medium text-gray-900">{{ $row['country'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ rtrim(rtrim(number_format($row['rate'] / 100, 2, ',', ''), '0'), ',') }} %</td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="App\Support\Money\Money::ofOre($row['net'])" /></td>
                                <td class="py-2 text-right"><x-ui.amount :money="App\Support\Money\Money::ofOre($row['vat'])" /></td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold text-gray-900">
                            <td class="pt-3 pr-4" colspan="2">{{ __('Summa') }}</td>
                            <td class="pt-3 pr-4 text-right"><x-ui.amount :money="App\Support\Money\Money::ofOre($ossTotals['net'])" /></td>
                            <td class="pt-3 text-right"><x-ui.amount :money="App\Support\Money\Money::ofOre($ossTotals['vat'])" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('Momsen är bokförd på 2619 och ingår inte i den svenska momsdeklarationen. Räkna om till euro med Europeiska centralbankens kurs för kvartalets sista dag.') }}</p>
        @endif
    </x-ui.card>
</div>
