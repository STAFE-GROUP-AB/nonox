@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
<div class="space-y-6">
    <x-ui.card>
        @if($canManage)
            <x-slot:cta>
                <a href="{{ route('manufacturing.boms.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-accent-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 sm:min-h-0">{{ __('Ny stycklista') }}</a>
            </x-slot:cta>
        @endif

        <label class="mb-4 block sm:max-w-xs">
            <span class="text-sm font-medium text-gray-700">{{ __('Sök stycklista') }}</span>
            <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}">
        </label>

        @if($rows === [])
            <x-ui.empty-state :title="$search === '' ? __('Inga stycklistor ännu') : __('Ingen stycklista matchar sökningen')">
                {{ __('Lägg upp vad en produkt består av, så räknar NONOX kostnaden för material och arbete per enhet. Produkten och komponenterna är vanliga artiklar; arbetsstegen läggs upp under Arbetssteg.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Produkt') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Version') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Komponenter') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Arbetssteg') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Material per enhet') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Arbete per enhet') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Kostnad per enhet') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($rows as $row)
                            <tr wire:key="bom-{{ $row['article']->id }}">
                                <td class="py-2 pr-4">
                                    <a href="{{ route('manufacturing.boms.show', $row['article']->id) }}" class="font-semibold text-accent-700 hover:underline">{{ $row['article']->name }}</a>
                                    <span class="block text-xs text-gray-500">{{ $row['article']->number }}</span>
                                </td>
                                <td class="py-2 pr-4">
                                    @if($row['current'])
                                        {{ __('Version :version från :date', ['version' => $row['current']->version, 'date' => $row['current']->valid_from->toDateString()]) }}
                                    @elseif($row['latest']->is_active)
                                        <x-ui.badge>{{ __('Gäller från :date', ['date' => $row['latest']->valid_from->toDateString()]) }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Inaktiv') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['components'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['operations'] }}</td>
                                @if($row['costing'] && $row['costing']->complete)
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['costing']->materialPerUnitOre)" /></td>
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['costing']->labourPerUnitOre)" /></td>
                                    <td class="py-2 pr-4 text-right font-semibold"><x-ui.amount :money="$money($row['costing']->perUnitOre())" /></td>
                                @elseif($row['costing'])
                                    <td colspan="3" class="py-2 pr-4 text-right text-accent-700">{{ __('Saknar pris') }}</td>
                                @else
                                    <td colspan="3" class="py-2 pr-4 text-right">–</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
