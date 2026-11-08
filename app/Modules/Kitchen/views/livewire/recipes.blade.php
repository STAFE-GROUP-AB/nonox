@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
<div class="space-y-6">
    <x-ui.card>
        @if($canManage)
            <x-slot:cta>
                <a href="{{ route('kitchen.recipes.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-accent-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 sm:min-h-0">{{ __('Nytt recept') }}</a>
            </x-slot:cta>
        @endif

        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="block grow sm:max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Sök recept') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="show" class="{{ $input }}">
                    <option value="alla">{{ __('Alla') }}</option>
                    <option value="ratter">{{ __('Rätter') }}</option>
                    <option value="grundrecept">{{ __('Grundrecept') }}</option>
                </select>
            </label>
        </div>

        @if($rows === [])
            <x-ui.empty-state :title="$search === '' ? __('Inga recept ännu') : __('Inget recept matchar sökningen')">
                {{ __('Lägg upp en rätt från menyn med dess ingredienser, så räknar NONOX portionskostnaden och marginalen. Rätten är en vanlig artikel med menypriset, ingredienserna lagerförda artiklar.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Recept') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Utbyte') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Version') }}</th>
                            @if($seesCosts)
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Kostnad per portion eller enhet') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($rows as $row)
                            <tr wire:key="recipe-{{ $row['article']->id }}">
                                <td class="py-2 pr-4">
                                    <a href="{{ route('kitchen.recipes.show', $row['article']->id) }}" class="font-semibold text-accent-700 hover:underline">{{ $row['article']->name }}</a>
                                    <span class="block text-xs text-gray-500">{{ $row['article']->number }}</span>
                                </td>
                                <td class="py-2 pr-4">{{ $row['dish'] ? __('Rätt') : __('Grundrecept') }}</td>
                                <td class="py-2 pr-4 tabular-nums">
                                    @php($recipe = $row['current'] ?? $row['latest'])
                                    {{ App\Modules\Inventory\Domain\Quantity::format($recipe->yield_milli) }} {{ $recipe->isPortions() ? __('portioner') : $row['article']->unit }}
                                </td>
                                <td class="py-2 pr-4">
                                    @if($row['current'])
                                        {{ __('Version :version från :date', ['version' => $row['current']->version, 'date' => $row['current']->valid_from->toDateString()]) }}
                                    @elseif($row['latest']->is_active)
                                        <x-ui.badge>{{ __('Gäller från :date', ['date' => $row['latest']->valid_from->toDateString()]) }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Inaktivt') }}</x-ui.badge>
                                    @endif
                                </td>
                                @if($seesCosts)
                                    <td class="py-2 pr-4 text-right">
                                        @if($row['cost'] !== null)
                                            <x-ui.amount :money="$money($row['cost'])" />
                                        @elseif($row['incomplete'])
                                            <span class="text-accent-700">{{ __('Saknar pris') }}</span>
                                        @else
                                            –
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
