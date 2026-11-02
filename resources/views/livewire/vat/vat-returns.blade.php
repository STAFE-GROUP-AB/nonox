@php
    use App\Domain\Vat\VatBoxes;
    use App\Domain\Vat\VatPeriods;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $boxes = $return?->boxes() ?? [];
    $shown = [5, 10, 11, 12, 48];
@endphp

<div class="space-y-6">
    @if(! $registered)
        <x-ui.empty-state :title="__('Företaget är inte momsregistrerat')">
            {{ __('Är företaget momsregistrerat, ändra redovisningsperioden för moms under företagets uppgifter.') }}
        </x-ui.empty-state>
    @elseif($fiscalYear === null)
        <x-ui.empty-state :title="__('Inget räkenskapsår ännu')">{{ __('Momsen visas när företaget har ett räkenskapsår.') }}</x-ui.empty-state>
    @else
        <x-ui.card class="print:hidden">
            <div class="grid gap-4 sm:grid-cols-[12rem_1fr] sm:items-end">
                <x-ui.field for="vat_year" :label="__('Räkenskapsår')">
                    <select id="vat_year" class="{{ $input }} mt-1" wire:model.live="ar">
                        @foreach($fiscalYears as $year)
                            <option value="{{ $year->id }}" @selected($year->id === $fiscalYear->id)>{{ $year->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <p class="text-sm text-gray-600">{{ __('Redovisningsperiod: :period', ['period' => mb_strtolower($company->vat_period->label())]) }}</p>
            </div>
            <ul class="mt-5 flex flex-wrap gap-2">
                @foreach($periods as $item)
                    @php($key = $item['start']->toDateString())
                    <li>
                        <button type="button" wire:click="choose('{{ $key }}')" @class([
                            'rounded-full px-3 py-1.5 text-sm font-medium',
                            'bg-gray-900 text-white' => $selected && $selected['start']->isSameDay($item['start']),
                            'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => ! ($selected && $selected['start']->isSameDay($item['start'])),
                        ])>
                            {{ VatPeriods::label($item['start'], $item['end']) }}
                            @if($reports->has($key))
                                <span aria-label="{{ __('bokförd') }}">✓</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        @if($selected && $return)
            <x-ui.card :title="__('Momsdeklaration :period', ['period' => VatPeriods::label($selected['start'], $selected['end'])])">
                <p class="text-sm text-gray-600">{{ $company->legal_name ?: $company->name }} · {{ $company->org_number }} · {{ $selected['start']->toDateString() }} – {{ $selected['end']->toDateString() }}</p>

                @if($report)
                    <p class="mt-3 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
                        ✓ {{ __('Momsen är bokförd på') }}
                        <a href="{{ route('accounting.vouchers.show', $report->voucher_id) }}" class="font-semibold underline">{{ $report->voucher?->reference() }}</a>.
                        @if($report->boxes != $boxes)
                            <span class="block mt-1 text-accent-800">{{ __('Något har bokförts i perioden efteråt, så rutorna nedan skiljer sig från det som bokfördes. Kontrollera och rätta deklarationen hos Skatteverket.') }}</span>
                        @elseif($report->version > 1)
                            <span class="block mt-1">{{ __('Ett tillägg har bokförts efter en sen bokföring i perioden. Rutorna nedan är den rättade deklarationen. Har du inte redan gjort det: lämna en rättad momsdeklaration för hela perioden hos Skatteverket.') }}</span>
                        @endif
                    </p>
                @endif

                @if($supplement && $canSettle)
                    <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-950 print:hidden" role="status">
                        <p class="font-semibold">{{ __('Bokför tillägget till momsen') }}</p>
                        <p class="mt-1">{{ __('Momsen för perioden är redan bokförd, men något har bokförts i perioden efteråt och ligger kvar på momskontona. Tillägget blir en ny verifikation i serie M som för skillnaden till 2650. Lämna sedan en rättad momsdeklaration för hela perioden hos Skatteverket, med rutorna nedan.') }}</p>
                        <div class="mt-3" x-data="{ confirming: false }">
                            <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Bokför tillägget') }}</x-button>
                            <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-4">
                                <x-button type="button" wire:click="settle" wire:loading.attr="disabled">{{ __('Ja, bokför tillägget') }}</x-button>
                                <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                            </div>
                            <x-input-error for="period" class="mt-2" />
                        </div>
                    </div>
                @endif

                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium w-16">{{ __('Ruta') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Vad') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Kronor') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach(VatBoxes::BOXES as $box => $definition)
                                @continue(($boxes[$box] ?? 0) === 0 && ! in_array($box, $shown, true))
                                <tr x-data="{ open: false }">
                                    <td class="py-2 pr-4 font-semibold tabular-nums align-top">{{ sprintf('%02d', $box) }}</td>
                                    <td class="py-2 pr-4 text-gray-700 align-top">
                                        {{ __($definition['label']) }}
                                        @if(! empty($return->sources[$box]))
                                            <button type="button" class="ms-2 text-xs text-accent-600 hover:text-accent-700 print:hidden" x-on:click="open = ! open">{{ __('Visa konton') }}</button>
                                            <ul x-show="open" x-cloak class="mt-1 space-y-0.5 text-xs text-gray-600">
                                                @foreach($return->sources[$box] as $source)
                                                    <li class="flex justify-between gap-4"><span><span class="tabular-nums">{{ $source['number'] }}</span> {{ $source['name'] }}</span><x-ui.amount :money="Money::ofOre($source['amount'])" /></li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right tabular-nums align-top">{{ number_format($boxes[$box] ?? 0, 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 text-base font-semibold">
                                <td class="pt-3 pr-4 tabular-nums">49</td>
                                <td class="pt-3 pr-4">{{ ($boxes[49] ?? 0) >= 0 ? __('Moms att betala') : __('Moms att få tillbaka') }}</td>
                                <td class="pt-3 text-right tabular-nums">{{ number_format(abs($boxes[49] ?? 0), 0, ',', ' ') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500">{{ __('Beloppen är i hela kronor; ören tas inte med. Rutor utan belopp visas inte.') }}</p>

                @if($return->unsettled !== [])
                    <div class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-950" role="status">
                        <p class="font-semibold">{{ __('Momskonton som inte ingår i deklarationen') }}</p>
                        <p class="mt-1">{{ __('De här kontona hör inte till någon ruta, till exempel vilande moms som ska redovisas först när kunden betalar. De tas inte med i deklarationen och flyttas inte till 2650 när momsen bokförs. Flytta beloppet till rätt momskonto i den period momsen ska redovisas.') }}</p>
                        <ul class="mt-2 space-y-0.5">
                            @foreach($return->unsettled as $account)
                                <li class="flex justify-between gap-4"><span><span class="tabular-nums">{{ $account['number'] }}</span> {{ $account['name'] }}</span><x-ui.amount :money="Money::ofOre($account['amount'])" /></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap items-center gap-4 print:hidden">
                    <a href="{{ route('vat.file', ['fiscalYear' => $fiscalYear, 'period' => $selected['start']->toDateString()]) }}" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner fil till Skatteverket') }}</a>
                    <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" x-on:click="window.print()">{{ __('Skriv ut eller spara som PDF') }}</button>
                </div>
            </x-ui.card>

            @if(! $report && $canSettle && ! $return->isEmpty())
                <x-ui.card :title="__('Bokför momsen')" class="print:hidden">
                    <p class="text-gray-700">{{ __('När deklarationen är lämnad bokförs momsen: momskontona som ingår i deklarationen nollställs och beloppet förs till 2650 Redovisningskonto för moms, med ören på 3740. Vilande moms och andra momskonton utan ruta lämnas orörda. Det blir en verifikation i serie M som inte kan ändras.') }}</p>
                    <div class="mt-4" x-data="{ confirming: false }">
                        <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Bokför momsen') }}</x-button>
                        <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-4">
                            <x-button type="button" wire:click="settle" wire:loading.attr="disabled">{{ __('Ja, bokför momsen') }}</x-button>
                            <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                        </div>
                        <x-input-error for="period" class="mt-2" />
                    </div>
                </x-ui.card>
            @endif

            @if($canLock && ($company->locked_until_date === null || $company->locked_until_date->lessThan($selected['end'])))
                <x-ui.card :title="__('Lås perioden')" class="print:hidden">
                    <p class="text-gray-700">{{ __('När momsen är deklarerad bör perioden låsas, så att inget kan bokföras i den i efterhand. Det går inte att låsa upp.') }}</p>
                    <div class="mt-4" x-data="{ confirming: false }">
                        <button type="button" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" x-show="! confirming" x-on:click="confirming = true">{{ __('Lås till och med :date', ['date' => $selected['end']->toDateString()]) }}</button>
                        <div x-show="confirming" x-cloak class="flex flex-wrap items-center gap-4">
                            <x-button type="button" wire:click="lock" wire:loading.attr="disabled">{{ __('Ja, lås perioden') }}</x-button>
                            <button type="button" class="text-sm text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                        </div>
                    </div>
                </x-ui.card>
            @elseif($company->locked_until_date !== null)
                <p class="text-sm text-gray-500">{{ __('Bokföringen är låst till och med :date.', ['date' => $company->locked_until_date->toDateString()]) }}</p>
            @endif
        @endif
    @endif
</div>
