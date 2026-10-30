@php($total = array_sum(array_column($rows, 'liability_ore')))
@php($contributions = array_sum(array_column($rows, 'contributions_ore')))
<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <x-ui.field for="liability_date" :label="__('Per dag')">
                <input id="liability_date" type="date" class="block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm mt-1" wire:model.live="date" value="{{ $day->toDateString() }}">
            </x-ui.field>
            <div class="flex flex-wrap gap-8 text-right">
                <div><p class="text-xs text-gray-500">{{ __('Semesterlöneskuld') }}</p><p class="text-xl font-semibold tabular-nums">{{ App\Support\Money\Money::ofOre($total)->format() }}</p></div>
                <div><p class="text-xs text-gray-500">{{ __('Sociala avgifter') }}</p><p class="text-xl font-semibold tabular-nums">{{ App\Support\Money\Money::ofOre($contributions)->format() }}</p></div>
                <div><p class="text-xs text-gray-500">{{ __('Bokfört per :date', ['date' => $booked?->as_of->toDateString() ?? '–']) }}</p><p class="text-xl font-semibold tabular-nums text-gray-600">{{ App\Support\Money\Money::ofOre((int) $booked?->liability_ore + (int) $booked?->contributions_ore)->format() }}</p></div>
            </div>
        </div>
        <p class="mt-3 text-sm text-gray-600">{{ __('Betalda semesterdagar som finns kvar plus dagarna som tjänas in nu, värderade till vad de kommer att kosta. Skulden bokförs med varje lönekörning på 2920 och 2940 när löneinställningarna säger varje månad.') }}</p>
    </x-ui.card>

    <x-ui.card>
        @if($missing)
            <p class="text-sm text-accent-700">{{ __('Regeln :rule är inte kontrollerad för dagen. Kontrollera den under Lön → Regler.', ['rule' => App\Modules\Payroll\Support\RulePresenter::name($missing)]) }}</p>
        @elseif($rows === [])
            <x-ui.empty-state :title="__('Ingen semesterlöneskuld')">{{ __('Det finns inga anställda med betald semester den dagen.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Dagar kvar') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Intjänas nu') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Skuld') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Sociala avgifter') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums">
                        @foreach($rows as $row)
                            <tr>
                                <td class="py-2 pr-4">{{ $row['name'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row['left'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ str_replace('.', ',', $row['earning']) }}</td>
                                <td class="py-2 pr-4 text-right">{{ App\Support\Money\Money::ofOre($row['liability_ore'])->format(withCurrency: false) }}</td>
                                <td class="py-2 text-right">{{ App\Support\Money\Money::ofOre($row['contributions_ore'])->format(withCurrency: false) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
