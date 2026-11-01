@php
    $money = fn (?int $ore) => $ore === null ? null : App\Support\Money\Money::ofOre($ore);
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs mt-1';
@endphp
<div class="space-y-6">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Rapporter') }}">
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('report', '{{ $key }}')" @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium',
                'bg-gray-900 text-white' => $current === $key,
                'text-gray-700 hover:bg-gray-100' => $current !== $key,
            ]) @if($current === $key) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    <x-ui.card>
        <div class="flex flex-wrap items-end gap-4">
            @if(in_array($current, ['ytd', 'reconciliation', 'year-end'], true))
                <x-ui.field for="report_year" :label="__('År')">
                    <input id="report_year" type="number" min="2000" max="2100" class="{{ $input }} w-28" wire:model.live.debounce.500ms="year" value="{{ $selectedYear }}" placeholder="{{ $selectedYear }}">
                </x-ui.field>
            @else
                <x-ui.field for="report_from" :label="__('Från utbetalningsmånad')">
                    <input id="report_from" type="month" class="{{ $input }}" wire:model.live="from" value="{{ $fromMonth }}">
                </x-ui.field>
                <x-ui.field for="report_to" :label="__('Till och med')">
                    <input id="report_to" type="month" class="{{ $input }}" wire:model.live="to" value="{{ $toMonth }}">
                </x-ui.field>
            @endif
        </div>

        <div class="mt-6 overflow-x-auto">
            @if($current === 'year-end')
                @if($closing)
                    <p class="text-sm font-medium text-gray-900">{{ __('Löneåret :year är avslutat :date.', ['year' => $closing->year, 'date' => $closing->created_at->toDateString()]) }}</p>
                    <dl class="mt-4 grid gap-4 sm:grid-cols-4 text-sm">
                        <div><dt class="text-gray-500">{{ __('Bruttolön') }}</dt><dd><x-ui.amount :money="$money($closing->totals['gross_ore'] ?? 0)" /></dd></div>
                        <div><dt class="text-gray-500">{{ __('Skatt') }}</dt><dd><x-ui.amount :money="$money($closing->totals['tax_ore'] ?? 0)" /></dd></div>
                        <div><dt class="text-gray-500">{{ __('Arbetsgivaravgifter') }}</dt><dd><x-ui.amount :money="$money($closing->totals['contributions_ore'] ?? 0)" /></dd></div>
                        <div><dt class="text-gray-500">{{ __('Nettolön') }}</dt><dd><x-ui.amount :money="$money($closing->totals['net_ore'] ?? 0)" /></dd></div>
                    </dl>
                @else
                    <p class="text-sm text-gray-600">{{ __('När året är slut, alla lönekörningar är bokförda och alla arbetsgivardeklarationer är inlämnade avslutas löneåret. Sedan kan ingen lön betalas ut i det, så årets summor står fast. En arbetsgivardeklaration för året kan fortfarande rättas. Semesteråret avslutas för sig under Semesterår.') }}</p>
                    @if($blockers !== [])
                        <ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-accent-700">
                            @foreach($blockers as $blocker)
                                <li>{{ $blocker }}</li>
                            @endforeach
                        </ul>
                    @elseif($canClose)
                        <div class="mt-4">
                            @if($confirmingClose)
                                <p class="text-sm font-medium text-gray-900">{{ __('Avsluta löneåret :year? Ingen lön kan sedan betalas ut med utbetalningsdag i året. Det går inte att ångra.', ['year' => $selectedYear]) }}</p>
                                <div class="mt-3 flex items-center gap-4">
                                    <x-button type="button" wire:click="closeYear" wire:loading.attr="disabled">{{ __('Ja, avsluta löneåret') }}</x-button>
                                    <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('confirmingClose', false)">{{ __('Avbryt') }}</button>
                                </div>
                            @else
                                <x-button type="button" wire:click="$set('confirmingClose', true)">{{ __('Avsluta löneåret :year', ['year' => $selectedYear]) }}</x-button>
                            @endif
                        </div>
                    @endif
                    <x-input-error for="year" class="mt-3" />
                @endif
            @elseif($rows === [])
                <x-ui.empty-state :title="__('Inget att visa')">{{ __('Det finns ingen attesterad lön för det valda urvalet.') }}</x-ui.empty-state>
            @else
                <table class="w-full text-sm">
                    @switch($current)
                        @case('journal')
                            <thead><tr class="border-b border-gray-200 text-left text-gray-500"><th class="py-2 pr-4 font-medium">{{ __('Lönekörning') }}</th><th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Bruttolön') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Skatt') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Nettolön') }}</th><th class="py-2 text-right font-medium">{{ __('Arbetsgivaravgifter') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($rows as $row)
                                    <tr><td class="py-2 pr-4">{{ $row['run'] }}<span class="block text-xs text-gray-500 tabular-nums">{{ $row['payment_date'] }}</span></td><td class="py-2 pr-4">{{ $row['number'] }} {{ $row['name'] }}</td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['gross_ore'])" :currency="false" /></td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['tax_ore'])" :currency="false" /></td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['net_ore'])" :currency="false" /></td><td class="py-2 text-right"><x-ui.amount :money="$money($row['contributions_ore'])" :currency="false" /></td></tr>
                                @endforeach
                            </tbody>
                            <tfoot><tr class="border-t border-gray-300 font-semibold"><td class="py-2 pr-4" colspan="2">{{ __('Summa') }}</td>@foreach(['gross_ore', 'tax_ore', 'net_ore', 'contributions_ore'] as $column)<td class="py-2 pr-4 text-right"><x-ui.amount :money="$money(array_sum(array_column($rows, $column)))" :currency="false" /></td>@endforeach</tr></tfoot>
                            @break
                        @case('pay-codes')
                            <thead><tr class="border-b border-gray-200 text-left text-gray-500"><th class="py-2 pr-4 font-medium">{{ __('Löneart') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Rader') }}</th><th class="py-2 text-right font-medium">{{ __('Belopp') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($rows as $row)
                                    <tr><td class="py-2 pr-4">{{ $row['name'] }} <span class="font-mono text-xs text-gray-400">{{ $row['code'] }}</span></td><td class="py-2 pr-4 text-right tabular-nums">{{ $row['lines'] }}</td><td class="py-2 text-right"><x-ui.amount :money="$money($row['amount_ore'])" :currency="false" /></td></tr>
                                @endforeach
                            </tbody>
                            @break
                        @case('ytd')
                            <thead><tr class="border-b border-gray-200 text-left text-gray-500"><th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Bruttolön') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Skatt') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Nettolön') }}</th><th class="py-2 text-right font-medium">{{ __('Arbetsgivaravgifter') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($rows as $row)
                                    <tr><td class="py-2 pr-4">{{ $row['number'] }} {{ $row['name'] }}</td>@foreach(['gross_ore', 'tax_ore', 'net_ore', 'contributions_ore'] as $column)<td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row[$column])" :currency="false" /></td>@endforeach</tr>
                                @endforeach
                            </tbody>
                            @break
                        @case('cost-centers')
                            <thead><tr class="border-b border-gray-200 text-left text-gray-500"><th class="py-2 pr-4 font-medium">{{ __('Kostnadsställe') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Bruttolön') }}</th><th class="py-2 text-right font-medium">{{ __('Arbetsgivaravgifter') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($rows as $row)
                                    <tr><td class="py-2 pr-4">{{ $row['code'] }} {{ $row['name'] }}</td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['gross_ore'])" :currency="false" /></td><td class="py-2 text-right"><x-ui.amount :money="$money($row['contributions_ore'])" :currency="false" /></td></tr>
                                @endforeach
                            </tbody>
                            @break
                        @case('pension')
                            <thead><tr class="border-b border-gray-200 text-left text-gray-500"><th class="py-2 pr-4 font-medium">{{ __('Pensionsplan') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Premie') }}</th><th class="py-2 text-right font-medium">{{ __('Särskild löneskatt') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($rows as $row)
                                    <tr><td class="py-2 pr-4">{{ $row['plan'] }}</td><td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['premium_ore'])" :currency="false" /></td><td class="py-2 text-right"><x-ui.amount :money="$money($row['special_tax_ore'])" :currency="false" /></td></tr>
                                @endforeach
                            </tbody>
                            @break
                        @case('reconciliation')
                            <thead><tr class="border-b border-gray-200 text-left text-gray-500"><th class="py-2 pr-4 font-medium">{{ __('Månad') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Skatt, lönebesked') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Skatt, deklarerad') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Skatt, bokförd (2710)') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Avgifter, lönebesked') }}</th><th class="py-2 pr-4 text-right font-medium">{{ __('Avgifter, deklarerade') }}</th><th class="py-2 text-right font-medium">{{ __('Avgifter, bokförda (7510)') }}</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($rows as $row)
                                    <tr>
                                        <td class="py-2 pr-4 tabular-nums">{{ $row['period'] }}</td>
                                        @foreach([['payslip_tax_ore', 'payslip_tax_ore'], ['declared_tax_ore', 'payslip_tax_ore'], ['booked_tax_ore', 'payslip_tax_ore'], ['payslip_contributions_ore', 'payslip_contributions_ore'], ['declared_contributions_ore', 'payslip_contributions_ore'], ['booked_contributions_ore', 'payslip_contributions_ore']] as [$column, $against])
                                            <td @class(['py-2 pr-4 text-right', 'text-accent-700 font-semibold' => $row[$column] !== null && intdiv($row[$column], 100) !== intdiv($row[$against], 100)])>
                                                @if($row[$column] === null)<span class="text-gray-400">–</span>@else<x-ui.amount :money="$money($row[$column])" :currency="false" />@endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                            @break
                    @endswitch
                </table>
                @if($current === 'reconciliation')
                    <p class="mt-3 text-xs text-gray-500">{{ __('Ett streck betyder att månaden inte är deklarerad eller bokförd ännu. Ett markerat belopp skiljer sig från lönebeskeden med mer än öresavrundning.') }}</p>
                @endif
            @endif
        </div>
    </x-ui.card>
</div>
