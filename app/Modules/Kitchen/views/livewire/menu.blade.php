@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
@php($qty = fn (int $milli) => App\Modules\Inventory\Domain\Quantity::format($milli))
@php($pct = fn (?int $bp) => App\Modules\Kitchen\Livewire\RecipePage::percent($bp))
<div class="space-y-6">
    <x-ui.card>
        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Sålt från') }}</span>
                <input type="date" wire:model.live="from" value="{{ $fromDate->toDateString() }}" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('till och med') }}</span>
                <input type="date" wire:model.live="to" value="{{ $toDate->toDateString() }}" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Sortera') }}</span>
                <select wire:model.live="sort" class="{{ $input }}">
                    <option value="namn">{{ __('Namn') }}</option>
                    <option value="salda">{{ __('Mest sålda') }}</option>
                    @if($seesCosts)
                        <option value="matkostnad">{{ __('Högst råvarukostnad') }}</option>
                        <option value="bidrag">{{ __('Högst täckningsbidrag') }}</option>
                        <option value="intjanat">{{ __('Mest intjänat') }}</option>
                    @endif
                </select>
            </label>
        </div>

        @if($rows === [])
            <x-ui.empty-state :title="__('Inga rätter med recept ännu')">
                {{ __('Lägg upp recept för rätterna under Recept. Då visas portionskostnad och marginal här, och med en kassakoppling även vad som har sålts.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Rätt') }}</th>
                            @if($seesCosts)
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Pris exkl. moms') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Portionskostnad') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Täckningsbidrag') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Råvarukostnad') }}</th>
                            @endif
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Sålda portioner') }}</th>
                            @if($seesCosts)
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Snittpris i kassan') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Intjänat bidrag') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($rows as $row)
                            <tr wire:key="dish-{{ $row['article_id'] }}">
                                <td class="py-2 pr-4"><a href="{{ route('kitchen.recipes.show', $row['article_id']) }}" class="font-semibold text-accent-700 hover:underline">{{ $row['name'] }}</a></td>
                                @if($seesCosts)
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($row['net_price_ore'])" /></td>
                                    <td class="py-2 pr-4 text-right">@if($row['portion_cost_ore'] !== null)<x-ui.amount :money="$money($row['portion_cost_ore'])" />@else<span class="text-accent-700">{{ __('Saknar pris') }}</span>@endif</td>
                                    <td class="py-2 pr-4 text-right">@if($row['contribution_ore'] !== null)<x-ui.amount :money="$money($row['contribution_ore'])" />@else – @endif</td>
                                    <td class="py-2 pr-4 text-right tabular-nums {{ $target !== null && $row['food_cost_bp'] !== null && $row['food_cost_bp'] > (int) $target ? 'font-semibold text-accent-700' : '' }}">{{ $pct($row['food_cost_bp']) }}</td>
                                @endif
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $qty($row['sold_milli']) }}</td>
                                @if($seesCosts)
                                    <td class="py-2 pr-4 text-right">@if($row['realised_price_ore'] !== null)<x-ui.amount :money="$money($row['realised_price_ore'])" />@else – @endif</td>
                                    <td class="py-2 pr-4 text-right">@if($row['earned_ore'] !== null)<x-ui.amount :money="$money($row['earned_ore'])" />@else – @endif</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($seesCosts)
                <p class="mt-4 text-sm text-gray-600">{{ __('Kalkyl – inte lagervärdering. Sålt enligt kassans försäljningsrader. Råvarukostnad över företagets mål är markerad; NONOX ändrar aldrig ett pris.') }}</p>
            @endif
        @endif
    </x-ui.card>
</div>
