@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
@php($qty = fn (int $milli) => App\Modules\Inventory\Domain\Quantity::format($milli))
@php($pct = fn (?int $bp) => App\Modules\Kitchen\Livewire\RecipePage::percent($bp))
<div class="space-y-6">
    @if($editing)
        <x-ui.card :title="$article ? __('Ny version av :name', ['name' => $article->name]) : __('Nytt recept')" :description="__('Varje ändring sparas som en ny version från ett datum. Försäljning före datumet räknas med den version som gällde då.')">
            <form wire:submit="save" class="grid gap-5 sm:grid-cols-6">
                @if($article === null)
                    <x-ui.field for="recipe-output" :label="__('Receptet gör')" error="form.output_article_id" class="sm:col-span-3" :hint="__('En rätt från menyn (en artikel med menypriset), eller ett grundrecept som sås eller fond (en lagerförd artikel).')">
                        <select id="recipe-output" wire:model="form.output_article_id" class="{{ $input }}" required>
                            <option value="">{{ __('Välj artikel') }}</option>
                            @foreach($articles as $option)
                                <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="recipe-name" :label="__('Namn')" error="form.name" class="sm:col-span-3">
                    <input id="recipe-name" wire:model="form.name" maxlength="255" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="recipe-yield" :label="__('Ger')" error="form.yield" class="sm:col-span-1">
                    <input id="recipe-yield" wire:model="form.yield" inputmode="decimal" class="{{ $input }} text-right tabular-nums" required>
                </x-ui.field>
                <x-ui.field for="recipe-yield-unit" :label="__('Utbyte i')" error="form.yield_unit" class="sm:col-span-2">
                    <select id="recipe-yield-unit" wire:model="form.yield_unit" class="{{ $input }}">
                        <option value="PORTIONS">{{ __('Portioner (en rätt)') }}</option>
                        <option value="UNIT">{{ __('Artikelns enhet (ett grundrecept)') }}</option>
                    </select>
                </x-ui.field>
                <x-ui.field for="recipe-valid-from" :label="__('Gäller från')" error="form.valid_from" class="sm:col-span-2">
                    <input id="recipe-valid-from" type="date" wire:model="form.valid_from" class="{{ $input }}" required>
                </x-ui.field>

                <fieldset class="sm:col-span-6">
                    <legend class="text-sm font-semibold text-ink">{{ __('Ingredienser') }}</legend>
                    <p class="mb-2 text-sm text-gray-600">{{ __('Nettomängd är det som hamnar i rätten. Svinn är skal, ben och putsning i procent av bruttomängden.') }}</p>
                    <x-input-error for="form.rows" class="mb-2" />
                    <div class="space-y-3">
                        @foreach($form['rows'] as $index => $row)
                            <div class="grid gap-3 rounded-2xl bg-stone-50 p-3 sm:grid-cols-12" wire:key="row-{{ $index }}">
                                <x-ui.field for="row-{{ $index }}-article" :label="__('Ingrediens')" error="form.rows.{{ $index }}.article_id" class="sm:col-span-4">
                                    <select id="row-{{ $index }}-article" wire:model.live="form.rows.{{ $index }}.article_id" class="{{ $input }}">
                                        <option value="">{{ __('Välj artikel') }}</option>
                                        @foreach($articles as $option)
                                            <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }} ({{ $option->unit }})</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <x-ui.field for="row-{{ $index }}-quantity" :label="__('Nettomängd')" error="form.rows.{{ $index }}.quantity" class="sm:col-span-2">
                                    <input id="row-{{ $index }}-quantity" wire:model="form.rows.{{ $index }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                </x-ui.field>
                                <x-ui.field for="row-{{ $index }}-unit" :label="__('Enhet')" error="form.rows.{{ $index }}.unit" class="sm:col-span-2">
                                    <select id="row-{{ $index }}-unit" wire:model="form.rows.{{ $index }}.unit" class="{{ $input }}">
                                        @foreach($units[(int) $row['article_id']] ?? [] as $unit)
                                            <option value="{{ $loop->first ? '' : $unit }}">{{ $unit }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                                <x-ui.field for="row-{{ $index }}-loss" :label="__('Svinn %')" error="form.rows.{{ $index }}.loss" class="sm:col-span-2">
                                    <input id="row-{{ $index }}-loss" wire:model="form.rows.{{ $index }}.loss" inputmode="decimal" class="{{ $input }} text-right tabular-nums" placeholder="0">
                                </x-ui.field>
                                <div class="flex items-end sm:col-span-2">
                                    <button type="button" wire:click="removeRow({{ $index }})" class="text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="addRow" class="mt-3 text-sm font-semibold text-accent-700 underline">{{ __('Lägg till ingrediens') }}</button>
                </fieldset>

                <x-ui.field for="recipe-method" :label="__('Tillagning')" error="form.method" class="sm:col-span-6">
                    <textarea id="recipe-method" wire:model="form.method" rows="4" class="{{ $input }}"></textarea>
                </x-ui.field>
                <x-ui.field for="recipe-allergens" :label="__('Allergener')" error="form.allergens" class="sm:col-span-6" :hint="__('Fritext för köket, åtskilda med komma. NONOX lämnar ingen allergeninformation till gäster.')">
                    <input id="recipe-allergens" wire:model="form.allergens" class="{{ $input }}" placeholder="{{ __('Till exempel: mjölk, gluten') }}">
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara receptet') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($article)
        <x-ui.card :title="$article->name" :description="$current ? __('Version :version, gäller från :date. Ger :yield :unit.', ['version' => $current->version, 'date' => $current->valid_from->toDateString(), 'yield' => $qty($current->yield_milli), 'unit' => $current->isPortions() ? __('portioner') : $article->unit]) : __('Inget recept gäller i dag.')">
            @if($canManage && ! $editing)
                <x-slot:cta>
                    <x-button type="button" wire:click="edit">{{ __('Ändra receptet') }}</x-button>
                </x-slot:cta>
            @endif

            @if($current)
                @if($seesCosts)
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Kalkyl – inte lagervärdering') }}</p>
                @endif
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Ingrediens') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Netto') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Svinn') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Brutto') }}</th>
                                @if($seesCosts)
                                    <th class="py-2 pr-4 text-right font-medium">{{ __('Pris per enhet') }}</th>
                                    <th class="py-2 pr-4 text-right font-medium">{{ __('Senaste inköp') }}</th>
                                    <th class="py-2 pr-4 text-right font-medium">{{ __('Kostnad') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($current->rows as $index => $row)
                                @php($line = $cost?->rows[$index] ?? null)
                                <tr wire:key="ingredient-{{ $row->id }}">
                                    <td class="py-2 pr-4">{{ $row->component?->name }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $qty($row->quantity_milli) }} {{ $row->component?->unit }}@if($row->entered_unit)<span class="block text-xs text-gray-500">{{ str_replace('.', ',', (string) $row->entered_quantity) }} {{ $row->entered_unit }}</span>@endif</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $pct($row->loss_bp) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $qty($row->grossMilli()) }} {{ $row->component?->unit }}</td>
                                    @if($seesCosts)
                                        <td class="py-2 pr-4 text-right">
                                            @if($line && $line['unit_cost_ore'] !== null)
                                                <x-ui.amount :money="$money($line['unit_cost_ore'])" />
                                                <span class="block text-xs text-gray-500">{{ ['valuation' => __('lagervärde'), 'latest' => __('senaste inköp'), 'purchase' => __('inköpspris'), 'structure' => __('grundrecept')][$line['source']] ?? '' }}</span>
                                            @else
                                                <span class="text-accent-700">{{ __('Saknar pris') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-right">
                                            @if($line && $line['latest'])
                                                @php($gap = $line['unit_cost_ore'] ? abs($line['latest']['ore'] - $line['unit_cost_ore']) * 10000 > $gapBp * $line['unit_cost_ore'] : false)
                                                <x-ui.amount :money="$money($line['latest']['ore'])" />
                                                <span class="block text-xs {{ $gap ? 'font-semibold text-accent-700' : 'text-gray-500' }}">{{ $line['latest']['date'] }}@if($gap) · {{ __('skiljer mer än 5 %') }}@endif</span>
                                            @else
                                                –
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-right">@if($line && $line['line_cost_ore'] !== null)<x-ui.amount :money="$money($line['line_cost_ore'])" />@else – @endif</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($seesCosts && $cost)
                    <dl class="mt-6 grid gap-3 sm:grid-cols-4">
                        <div class="rounded-2xl bg-stone-50 p-4">
                            <dt class="text-sm text-gray-500">{{ __('Kostnad för hela satsen') }}</dt>
                            <dd class="mt-1 text-xl font-bold"><x-ui.amount :money="$money($cost->batchCostOre)" /></dd>
                        </div>
                        <div class="rounded-2xl bg-stone-50 p-4">
                            <dt class="text-sm text-gray-500">{{ $current->isPortions() ? __('Kostnad per portion') : __('Kostnad per :unit', ['unit' => $article->unit]) }}</dt>
                            <dd class="mt-1 text-xl font-bold">@if($cost->complete)<x-ui.amount :money="$money($cost->perUnitOre)" />@else<span class="text-accent-700">{{ __('Ofullständig – en ingrediens saknar pris') }}</span>@endif</dd>
                        </div>
                        @if($margin)
                            <div class="rounded-2xl bg-stone-50 p-4">
                                <dt class="text-sm text-gray-500">{{ __('Täckningsbidrag per portion') }}</dt>
                                <dd class="mt-1 text-xl font-bold"><x-ui.amount :money="$money($margin['contribution'])" /></dd>
                                <dd class="text-xs text-gray-500">{{ __('Menypris :price exklusive moms', ['price' => $money($margin['net'])->format()]) }}</dd>
                            </div>
                            <div class="rounded-2xl bg-stone-50 p-4">
                                <dt class="text-sm text-gray-500">{{ __('Råvarukostnad i procent') }}</dt>
                                <dd class="mt-1 text-xl font-bold {{ $target !== null && $margin['food_cost_bp'] > (int) $target ? 'text-accent-700' : '' }}">{{ $pct($margin['food_cost_bp']) }}</dd>
                                <dd class="text-xs text-gray-500">{{ __('Marginal :margin', ['margin' => $pct($margin['margin_bp'])]) }}</dd>
                            </div>
                        @endif
                    </dl>
                    <p class="mt-3 text-sm text-gray-600">{{ __('Kostnaden räknas från lagrets värde enligt företagets värderingsmetod, samma siffra som i bokföringen. Ett grundrecept i kylen värderas till ingredienskostnad, exklusive arbete.') }}</p>
                @endif

                @if($current->method)
                    <h3 class="mt-6 text-sm font-semibold text-ink">{{ __('Tillagning') }}</h3>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $current->method }}</p>
                @endif
                @if($current->allergens)
                    <h3 class="mt-6 text-sm font-semibold text-ink">{{ __('Allergener') }}</h3>
                    <p class="mt-1 flex flex-wrap gap-2">@foreach($current->allergens as $tag)<x-ui.badge>{{ $tag }}</x-ui.badge>@endforeach</p>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Köksanteckning, ingen allergeninformation till gäster.') }}</p>
                @endif
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Versioner')">
            <ul class="divide-y divide-stone-200 text-sm">
                @foreach($versions as $version)
                    <li class="flex flex-wrap justify-between gap-3 py-2" wire:key="version-{{ $version->id }}">
                        <span>{{ __('Version :version', ['version' => $version->version]) }} · {{ __('gäller från :date', ['date' => $version->valid_from->toDateString()]) }}</span>
                        <span class="text-gray-500">{{ $version->is_active ? __('Aktiv') : __('Inaktiv') }}</span>
                    </li>
                @endforeach
            </ul>
            @if($canManage)
                <div class="mt-4">
                    @if($versions->contains('is_active', true))
                        <button type="button" wire:click="setActive(false)" wire:confirm="{{ __('Inaktivera receptet? Försäljning av rätten dras inte längre via receptet, och hela historiken visas under Sålt utan recept tills receptet används igen.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Inaktivera receptet') }}</button>
                    @else
                        <button type="button" wire:click="setActive(true)" class="text-sm font-semibold text-accent-700 underline">{{ __('Använd receptet igen') }}</button>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
