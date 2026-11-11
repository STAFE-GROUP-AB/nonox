@php($hours = fn (int $minutes) => App\Modules\Manufacturing\Livewire\Planning\ResourcesPage::hours($minutes))
<div class="space-y-4">
    @include('manufacturing::livewire.planning.run-bar')

    <div class="flex flex-wrap items-center gap-2">
        <x-secondary-button type="button" wire:click="shift(-7)">{{ __('Föregående vecka') }}</x-secondary-button>
        <x-secondary-button type="button" wire:click="shift(7)">{{ __('Nästa vecka') }}</x-secondary-button>
    </div>

    @if($load === null || ($load['people'] === [] && $load['workstations'] === []))
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga resurser ännu')">
                {{ __('Ge personerna i verkstaden sina kompetenser under Inställningar, och lägg upp arbetsstationer som lackbox eller kapsåg. Sedan visas deras beläggning här.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        @foreach(['people' => __('Personer'), 'workstations' => __('Arbetsstationer')] as $group => $title)
            @if($load[$group] !== [])
                <x-ui.card :title="$title">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead>
                                <tr class="border-b border-stone-200 text-gray-500">
                                    <th class="py-2 pr-3 text-left font-medium">{{ $group === 'people' ? __('Person') : __('Arbetsstation') }}</th>
                                    @foreach($load['days'] as $day)
                                        @php($date = Carbon\CarbonImmutable::parse($day))
                                        <th @class(['px-1 py-2 text-center font-medium', 'text-gray-400' => $date->isWeekend()])>{{ $date->locale('sv')->isoFormat('dd D/M') }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach($load[$group] as $row)
                                    <tr wire:key="{{ $group }}-{{ $row['id'] }}">
                                        <th scope="row" class="py-2 pr-3 text-left font-semibold text-ink">{{ $row['name'] }}</th>
                                        @foreach($row['days'] as $day => $cell)
                                            @php($share = $cell['available'] > 0 ? min(100, intdiv($cell['booked'] * 100, $cell['available'])) : 0)
                                            <td class="px-1 py-2 text-center tabular-nums">
                                                @if($cell['absent'])
                                                    <x-ui.badge>{{ __('Frånvarande') }}</x-ui.badge>
                                                @elseif($group === 'workstations')
                                                    @if($cell['available'] < 1440)<span class="block text-red-700">{{ __('Ur drift') }}</span>@endif
                                                    {{ $cell['booked'] > 0 ? $hours($cell['booked']) : '' }}
                                                @elseif($cell['available'] === 0)
                                                    <span class="text-gray-300">–</span>
                                                @else
                                                    <span @class(['block rounded px-1', 'bg-accent-100 text-accent-900' => $share >= 75, 'bg-stone-100' => $share < 75])>{{ $hours($cell['booked']) }} / {{ $hours($cell['available']) }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif
        @endforeach
        <p class="text-xs text-gray-500">{{ __('Frånvaro visas likadant oavsett orsak. Orsaken finns bara i lönen, för dem som får se den där.') }}</p>
    @endif
</div>
