@php($kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format(withCurrency: false))
<div class="space-y-6">
    <x-ui.card :title="__('Semesteråret :from – :to', ['from' => $yearStart->toDateString(), 'to' => $yearEnd->toDateString()])"
               :description="__('När semesteråret är slut sparas eller betalas de dagar som inte tagits ut. Bara dagar över 20 får sparas, och inte ett år då sparade dagar har använts. Sparade dagar ska tas ut inom fem år.')">
        @if($closed->isNotEmpty())
            <p class="text-sm font-medium text-gray-900">{{ __('Stängt :date.', ['date' => $closed->first()->created_at?->toDateString()]) }}</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Sparade') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Utbetalda dagar') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                            <th class="py-2 font-medium">{{ __('Betalas') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums">
                        @foreach($closed as $row)
                            <tr>
                                <td class="py-2 pr-4">{{ $row->employee?->first_name }} {{ $row->employee?->last_name }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row->days_saved }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row->days_paid_out }}</td>
                                <td class="py-2 pr-4 text-right">{{ $kr($row->payout_ore + $row->variable_pay_ore) }}</td>
                                <td class="py-2 text-gray-600">{{ $row->payroll_run_id ? __('Utbetalt') : __('I nästa lönekörning') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif($error)
            <p class="text-sm text-accent-700">{{ $error }}</p>
        @elseif($preview === [])
            <x-ui.empty-state :title="__('Inga anställda med betald semester')">{{ __('Det finns inget att stänga för semesteråret.') }}</x-ui.empty-state>
        @else
            <form wire:submit="close" class="space-y-4">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Betalda dagar') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Uttagna') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Kvar') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Spara') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Betalas ut') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Rörlig lön, 12 %') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 tabular-nums">
                            @foreach($preview as $id => $row)
                                @php($saved = max(0, min((int) ($save[$id] ?? 0), $row['max_save'])))
                                <tr wire:key="year-{{ $id }}">
                                    <td class="py-2 pr-4">{{ $row['employee']->first_name }} {{ $row['employee']->last_name }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['earned'] }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['taken'] }}@if($row['saved_used'] > 0) <span class="block text-xs text-gray-500">{{ __('varav :count sparade', ['count' => $row['saved_used']]) }}</span>@endif</td>
                                    <td class="py-2 pr-4 text-right">{{ $row['left'] }}</td>
                                    <td class="py-2 pr-4">
                                        @if($row['max_save'] > 0)
                                            <input type="number" min="0" max="{{ $row['max_save'] }}" class="block w-20 rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500" wire:model.live="save.{{ $id }}" aria-label="{{ __('Dagar att spara för :name', ['name' => $row['employee']->first_name]) }}">
                                            <span class="text-xs text-gray-500">{{ __('högst :max', ['max' => $row['max_save']]) }}</span>
                                            <x-input-error :for="'save.'.$id" />
                                        @else
                                            <span class="text-xs text-gray-500">–</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">{{ __(':count dagar', ['count' => $row['left'] - $saved]) }}<span class="block text-xs text-gray-500">{{ $kr(($row['left'] - $saved) * $row['day_value_ore']) }}</span></td>
                                    <td class="py-2 text-right">{{ $kr($row['variable_pay_ore']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-button wire:loading.attr="disabled">{{ __('Stäng semesteråret') }}</x-button>
                <p class="text-xs text-gray-500">{{ __('Utbetalningarna kommer med i nästa lönekörning och beskattas som engångsbelopp. Ett stängt semesterår går inte att ändra.') }}</p>
            </form>
        @endif
    </x-ui.card>
</div>
