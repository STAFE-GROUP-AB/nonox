@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $ore): string => \App\Support\Money\Money::ofOre($ore)->format();
    $difference = $splitTotal === null ? null : $asset->acquisition_ore->ore - $splitTotal;
@endphp
<section aria-labelledby="components-heading" class="space-y-4">
    <div>
        <h3 id="components-heading" class="text-sm font-semibold text-ink">{{ __('Komponenter') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('Dela upp :name i delar som förbrukas olika fort, och skriv av varje del för sig (K3 17.4). En byggnad delas alltid upp i mark och betydande komponenter, till exempel stomme, tak, fasad och stammar. Mark skrivs inte av.', ['name' => $asset->name]) }}</p>
    </div>

    <dl class="grid gap-3 text-sm sm:grid-cols-3">
        <div><dt class="text-gray-500">{{ __('Anskaffningsvärde') }}</dt><dd class="font-medium tabular-nums">{{ $asset->acquisition_ore->format() }}</dd></div>
        <div><dt class="text-gray-500">{{ __('Avskrivet hittills') }}</dt><dd class="font-medium tabular-nums">{{ $kr($asset->accumulatedOre()) }}</dd></div>
        <div><dt class="text-gray-500">{{ __('Kvar att fördela') }}</dt><dd @class(['font-medium tabular-nums', 'text-red-700' => $difference !== 0])>{{ $difference === null ? '–' : $kr($difference) }}</dd></div>
    </dl>

    <form wire:submit="reviewSplit" class="space-y-4">
        <x-ui.field for="split_date" :label="__('Gäller från')" error="split_date" :hint="__('Den första dagen i en månad. Avskrivningarna före den här dagen ska vara bokförda.')">
            <input id="split_date" type="date" class="{{ $input }} mt-1 w-48" wire:model="splitDate">
        </x-ui.field>

        <x-input-error for="components" class="mt-1" />

        <div class="-mx-3 overflow-x-auto sm:mx-0">
            <table class="min-w-full text-left text-sm">
                <thead>
                    <tr class="text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                        <th scope="col" class="px-2 py-2">{{ __('Benämning') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('Slag') }}</th>
                        <th scope="col" class="px-2 py-2 text-right">{{ __('Anskaffningsvärde, kr') }}</th>
                        <th scope="col" class="px-2 py-2 text-right">{{ __('Redan avskrivet, kr') }}</th>
                        <th scope="col" class="px-2 py-2 text-right">{{ __('Återstående nyttjandeperiod, månader') }}</th>
                        <th scope="col" class="px-2 py-2 text-right">{{ __('Total nyttjandeperiod, månader') }}</th>
                        <th scope="col" class="px-2 py-2 text-right">{{ __('Restvärde, kr') }}</th>
                        <th scope="col" class="px-2 py-2">{{ __('Tillgångskonto') }}</th>
                        <th scope="col" class="px-2 py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($components as $index => $component)
                        @php $land = $component['kind'] === \App\Actions\Assets\FixedAssets::COMPONENT_LAND; @endphp
                        <tr wire:key="component-{{ $index }}" class="align-top">
                            <td class="px-2 py-1">
                                <label for="component_name_{{ $index }}" class="sr-only">{{ __('Benämning') }}</label>
                                <input id="component_name_{{ $index }}" type="text" class="{{ $input }} min-w-40" wire:model="components.{{ $index }}.name">
                                <x-input-error for="components.{{ $index }}.name" class="mt-1" />
                            </td>
                            <td class="px-2 py-1">
                                <label for="component_kind_{{ $index }}" class="sr-only">{{ __('Slag') }}</label>
                                <select id="component_kind_{{ $index }}" class="{{ $input }}" wire:model.live="components.{{ $index }}.kind">
                                    <option value="part">{{ __('Del som skrivs av') }}</option>
                                    @if($asset->category === \App\Domain\Assets\AssetCategory::Buildings)
                                        <option value="land">{{ __('Mark') }}</option>
                                    @endif
                                </select>
                                <x-input-error for="components.{{ $index }}.kind" class="mt-1" />
                            </td>
                            <td class="px-2 py-1">
                                <label for="component_cost_{{ $index }}" class="sr-only">{{ __('Anskaffningsvärde, kr') }}</label>
                                <input id="component_cost_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} w-36 text-right tabular-nums" wire:model.blur="components.{{ $index }}.cost">
                                <x-input-error for="components.{{ $index }}.cost" class="mt-1" />
                            </td>
                            <td class="px-2 py-1">
                                @unless($land)
                                    <label for="component_accumulated_{{ $index }}" class="sr-only">{{ __('Redan avskrivet, kr') }}</label>
                                    <input id="component_accumulated_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} w-36 text-right tabular-nums" wire:model="components.{{ $index }}.accumulated" placeholder="{{ __('Fördelas') }}">
                                    <x-input-error for="components.{{ $index }}.accumulated" class="mt-1" />
                                @endunless
                            </td>
                            <td class="px-2 py-1">
                                @if($land)
                                    <span class="block py-2 text-right text-gray-500">{{ __('Skrivs inte av') }}</span>
                                @else
                                    <label for="component_life_{{ $index }}" class="sr-only">{{ __('Återstående nyttjandeperiod, månader') }}</label>
                                    <input id="component_life_{{ $index }}" type="number" min="1" class="{{ $input }} w-28 text-right tabular-nums" wire:model="components.{{ $index }}.life_months">
                                    <x-input-error for="components.{{ $index }}.life_months" class="mt-1" />
                                @endif
                            </td>
                            <td class="px-2 py-1">
                                @unless($land)
                                    <label for="component_total_life_{{ $index }}" class="sr-only">{{ __('Total nyttjandeperiod, månader') }}</label>
                                    <input id="component_total_life_{{ $index }}" type="number" min="1" class="{{ $input }} w-28 text-right tabular-nums" wire:model="components.{{ $index }}.total_life_months" placeholder="{{ __('Beräknas') }}">
                                    <x-input-error for="components.{{ $index }}.total_life_months" class="mt-1" />
                                @endunless
                            </td>
                            <td class="px-2 py-1">
                                @unless($land)
                                    <label for="component_residual_{{ $index }}" class="sr-only">{{ __('Restvärde, kr') }}</label>
                                    <input id="component_residual_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} w-32 text-right tabular-nums" wire:model="components.{{ $index }}.residual">
                                    <x-input-error for="components.{{ $index }}.residual" class="mt-1" />
                                @endunless
                            </td>
                            <td class="px-2 py-1">
                                <label for="component_account_{{ $index }}" class="sr-only">{{ __('Tillgångskonto') }}</label>
                                <input id="component_account_{{ $index }}" type="number" class="{{ $input }} w-24 tabular-nums" wire:model="components.{{ $index }}.asset_account">
                                <x-input-error for="components.{{ $index }}.asset_account" class="mt-1" />
                            </td>
                            <td class="px-2 py-1 text-right">
                                @if(count($components) > 2)
                                    <button type="button" class="py-2 text-sm text-gray-600 underline" wire:click="removeComponent({{ $index }})">{{ __('Ta bort') }}<span class="sr-only"> {{ $component['name'] }}</span></button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-sm text-gray-600">{{ __('Anskaffningsvärdena ska tillsammans bli tillgångens anskaffningsvärde. Lämnar du Redan avskrivet tomt fördelas det som är avskrivet på delarna som skrivs av, i förhållande till deras anskaffningsvärde. Varje del skrivs sedan av på sitt redovisade värde under den återstående nyttjandeperioden.') }}</p>
        <p class="text-sm text-gray-600">{{ __('Den totala nyttjandeperioden står i årsredovisningens principnot. Lämnar du den tom blir den den återstående perioden plus månaderna sedan tillgångens första avskrivningsmånad. Har tillgången ett restvärde fördelas det på delarna i förhållande till anskaffningsvärdet när alla delar har ett anskaffningsvärde.') }}</p>

        @unless($confirmingSplit)
            <div class="flex flex-wrap items-center gap-4">
                <x-button>{{ __('Granska uppdelningen') }}</x-button>
                @if(count($components) < 20)
                    <x-secondary-button type="button" wire:click="addComponent">{{ __('Lägg till komponent') }}</x-secondary-button>
                @endif
                <button type="button" class="text-sm text-gray-600 underline" wire:click="cancelSplit">{{ __('Avbryt') }}</button>
            </div>
        @endunless
    </form>

    @if($confirmingSplit)
        <div role="alertdialog" aria-labelledby="split-confirm-title" aria-describedby="split-confirm-text" class="rounded-2xl bg-white p-5 ring-1 ring-amber-300"
             x-data x-init="$nextTick(() => $refs.confirmSplit.focus())" x-on:keydown.escape.prevent="$wire.set('confirmingSplit', false)">
            <h4 id="split-confirm-title" class="font-semibold text-ink">{{ __('Dela upp :name i :count komponenter?', ['name' => $asset->name, 'count' => count($components)]) }}</h4>
            <div id="split-confirm-text" class="mt-2 space-y-2 text-sm text-gray-700">
                <p>{{ __('Uppdelningen går inte att ångra. Från :date skrivs :name inte av som en helhet längre, utan varje komponent skrivs av för sig. Mark skrivs aldrig av.', ['date' => $splitDate, 'name' => $asset->name]) }}</p>
                <p>{{ __('Avskrivningar som redan är bokförda ändras inte, och inga månader före :date bokförs i efterhand.', ['date' => $splitDate]) }}</p>
                <p>{{ __('Har en komponent ett annat tillgångskonto än tillgången, till exempel mark på 1130, bokförs en omföring av dess anskaffningsvärde den :date.', ['date' => $splitDate]) }}</p>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="text-xs font-medium uppercase tracking-[0.12em] text-gray-500">
                            <th scope="col" class="px-2 py-1">{{ __('Komponent') }}</th>
                            <th scope="col" class="px-2 py-1 text-right">{{ __('Anskaffningsvärde') }}</th>
                            <th scope="col" class="px-2 py-1 text-right">{{ __('Restvärde') }}</th>
                            <th scope="col" class="px-2 py-1 text-right">{{ __('Återstående nyttjandeperiod, månader') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($reviewRows as $row)
                            <tr>
                                <td class="px-2 py-1">{{ $row['name'] }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ $row['cost'] }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ $row['residual'] }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ $row['life'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <x-button type="button" x-ref="confirmSplit" wire:click="split" wire:loading.attr="disabled">{{ __('Dela upp tillgången') }}</x-button>
                <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('confirmingSplit', false)">{{ __('Tillbaka') }}</button>
            </div>
        </div>
    @endif
</section>
