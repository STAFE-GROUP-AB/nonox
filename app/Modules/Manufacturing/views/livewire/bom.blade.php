@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
@php($qty = fn (int $milli) => App\Modules\Inventory\Domain\Quantity::format($milli))
@php($pct = fn (?int $bp) => App\Modules\Manufacturing\Livewire\BillOfMaterialsPage::percent($bp))
@php($sources = ['valuation' => __('lagervärde'), 'latest' => __('senaste inköp'), 'purchase' => __('inköpspris'), 'structure' => __('egen stycklista')])
<div class="space-y-6">
    @if($editing)
        <x-ui.card :title="$article ? __('Ny version av :name', ['name' => $article->name]) : __('Ny stycklista')" :description="__('Varje ändring sparas som en ny version från ett datum. Offerter och ordrar behåller den stycklista de redan har.')">
            <form wire:submit="save" class="grid gap-5 sm:grid-cols-6">
                @if($article === null)
                    <x-ui.field for="bom-output" :label="__('Stycklistan gör')" error="form.output_article_id" class="sm:col-span-3" :hint="__('Produkten som tillverkas, en vanlig artikel med försäljningspriset.')">
                        <select id="bom-output" wire:model="form.output_article_id" class="{{ $input }}" required>
                            <option value="">{{ __('Välj artikel') }}</option>
                            @foreach($articles as $option)
                                <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="bom-name" :label="__('Namn')" error="form.name" class="sm:col-span-3">
                    <input id="bom-name" wire:model="form.name" maxlength="255" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="bom-yield" :label="__('Gäller för antal')" error="form.yield" class="sm:col-span-2" :hint="__('Hur många av produkten raderna räcker till. Oftast 1.')">
                    <input id="bom-yield" wire:model="form.yield" inputmode="decimal" class="{{ $input }} text-right tabular-nums" required>
                </x-ui.field>
                <x-ui.field for="bom-valid-from" :label="__('Gäller från')" error="form.valid_from" class="sm:col-span-2">
                    <input id="bom-valid-from" type="date" wire:model.live="form.valid_from" class="{{ $input }}" required>
                </x-ui.field>
                @if(($form['valid_from'] ?? '') !== '' && ($form['valid_from'] ?? '') < now()->toDateString() || $errors->has('form.valid_from'))
                    <label class="flex items-start gap-3 sm:col-span-6">
                        <input type="checkbox" wire:model="form.confirm_backdate" class="mt-1 rounded-sm border-stone-300 text-ink focus:ring-accent-500">
                        <span class="text-sm text-gray-700">{{ __('Stycklistan ska gälla bakåt i tiden.') }}</span>
                    </label>
                @endif

                <fieldset class="sm:col-span-6">
                    <legend class="text-sm font-semibold text-ink">{{ __('Komponenter och arbetssteg') }}</legend>
                    <p class="mb-2 text-sm text-gray-600">{{ __('Nettomängd är det som blir kvar i produkten. Spill är kap och bortfall i procent av bruttomängden. Minuterna gäller hela antalet ovan.') }}</p>
                    <x-input-error for="form.rows" class="mb-2" />
                    <div class="space-y-3">
                        @foreach($form['rows'] as $index => $row)
                            <div class="grid gap-3 rounded-2xl bg-stone-50 p-3 sm:grid-cols-12" wire:key="row-{{ $index }}">
                                @if(($row['type'] ?? 'component') === 'operation')
                                    <x-ui.field for="row-{{ $index }}-operation" :label="__('Arbetssteg')" error="form.rows.{{ $index }}.operation_id" class="sm:col-span-6">
                                        <select id="row-{{ $index }}-operation" wire:model="form.rows.{{ $index }}.operation_id" class="{{ $input }}">
                                            <option value="">{{ __('Välj arbetssteg') }}</option>
                                            @foreach($activeOperations as $option)
                                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                    <x-ui.field for="row-{{ $index }}-minutes" :label="__('Minuter')" error="form.rows.{{ $index }}.minutes" class="sm:col-span-2">
                                        <input id="row-{{ $index }}-minutes" wire:model="form.rows.{{ $index }}.minutes" inputmode="numeric" class="{{ $input }} text-right tabular-nums">
                                    </x-ui.field>
                                    <div class="sm:col-span-2"></div>
                                @else
                                    <x-ui.field for="row-{{ $index }}-article" :label="__('Komponent')" error="form.rows.{{ $index }}.article_id" class="sm:col-span-4">
                                        <select id="row-{{ $index }}-article" wire:model.live="form.rows.{{ $index }}.article_id" class="{{ $input }}">
                                            <option value="">{{ __('Välj artikel') }}</option>
                                            @foreach($articles as $option)
                                                <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }} ({{ $option->stockUnit?->code ?? $option->unit }})</option>
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
                                    <x-ui.field for="row-{{ $index }}-loss" :label="__('Spill %')" error="form.rows.{{ $index }}.loss" class="sm:col-span-2">
                                        <input id="row-{{ $index }}-loss" wire:model="form.rows.{{ $index }}.loss" inputmode="decimal" class="{{ $input }} text-right tabular-nums" placeholder="0">
                                    </x-ui.field>
                                @endif
                                <div class="flex items-end sm:col-span-2">
                                    <button type="button" wire:click="removeRow({{ $index }})" class="text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex flex-wrap gap-4">
                        <button type="button" wire:click="addRow('component')" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till komponent') }}</button>
                        <button type="button" wire:click="addRow('operation')" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till arbetssteg') }}</button>
                        @if($activeOperations->isEmpty())
                            <span class="text-sm text-gray-600">{{ __('Inga arbetssteg ännu.') }} <a href="{{ route('manufacturing.operations') }}" class="font-semibold text-accent-700 underline">{{ __('Lägg upp arbetssteg') }}</a></span>
                        @endif
                    </div>
                </fieldset>

                <x-ui.field for="bom-method" :label="__('Anteckningar för verkstaden')" error="form.method" class="sm:col-span-6">
                    <textarea id="bom-method" wire:model="form.method" rows="3" class="{{ $input }}"></textarea>
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara stycklistan') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($article)
        <x-ui.card :title="$article->name" :description="$current ? __('Version :version, gäller från :date. Gäller för :yield :unit.', ['version' => $current->version, 'date' => $current->valid_from->toDateString(), 'yield' => $qty($current->yield_milli), 'unit' => $article->stockUnit?->code ?? $article->unit]) : __('Ingen stycklista gäller i dag.')">
            @if($canManage && ! $editing)
                <x-slot:cta>
                    <x-button type="button" wire:click="edit">{{ __('Ändra stycklistan') }}</x-button>
                </x-slot:cta>
            @endif

            @if($current && $costing)
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Kalkyl – inte lagervärdering') }}</p>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Komponent eller arbetssteg') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Netto') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Spill') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Brutto eller tid') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Pris per enhet') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Material') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Arbete') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($costing->rows as $index => $line)
                                @php($row = $line['line'])
                                <tr wire:key="bom-row-{{ $row->id }}">
                                    @if($row->operation_id !== null)
                                        <td class="py-2 pr-4">{{ $line['operation']?->name ?? __('Arbetssteg') }}</td>
                                        <td class="py-2 pr-4 text-right">–</td>
                                        <td class="py-2 pr-4 text-right">–</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ App\Modules\Manufacturing\Livewire\BillOfMaterialsPage::duration((int) $row->minutes) }}</td>
                                        <td class="py-2 pr-4 text-right">@if($line['hourly_cost_ore'] !== null)<x-ui.amount :money="$money($line['hourly_cost_ore'])" /><span class="block text-xs text-gray-500">{{ __('per timme') }}</span>@else – @endif</td>
                                        <td class="py-2 pr-4 text-right">–</td>
                                        <td class="py-2 pr-4 text-right">@if($line['labour_ore'] !== null)<x-ui.amount :money="$money($line['labour_ore'])" />@else – @endif</td>
                                    @else
                                        @php($unit = $row->component?->stockUnit?->code ?? $row->component?->unit)
                                        <td class="py-2 pr-4">{{ $row->component?->name }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">
                                            @if($row->entered_unit){{ str_replace('.', ',', (string) $row->entered_quantity) }} {{ $row->entered_unit }}<span class="block text-xs text-gray-500">≈ {{ $qty($row->quantity_milli) }} {{ $unit }}</span>@else{{ $qty($row->quantity_milli) }} {{ $unit }}@endif
                                        </td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $pct($row->loss_bp) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $qty($row->grossMilli()) }} {{ $unit }}</td>
                                        <td class="py-2 pr-4 text-right">
                                            @if($line['unit_cost_ore'] !== null)
                                                <x-ui.amount :money="$money($line['unit_cost_ore'])" />
                                                <span class="block text-xs text-gray-500">{{ $sources[$line['source']] ?? '' }}</span>
                                            @else
                                                <span class="text-accent-700">{{ __('Saknar pris') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-right">@if($line['material_ore'] !== null)<x-ui.amount :money="$money($line['material_ore'])" />@else – @endif</td>
                                        <td class="py-2 pr-4 text-right">@if($line['labour_ore'])<x-ui.amount :money="$money($line['labour_ore'])" />@else – @endif</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @include('manufacturing::livewire.partials.costing-summary', ['costing' => $costing, 'unit' => $article->stockUnit?->code ?? $article->unit])

                @if($current->method)
                    <h3 class="mt-6 text-sm font-semibold text-ink">{{ __('Anteckningar för verkstaden') }}</h3>
                    <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $current->method }}</p>
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
                        <button type="button" wire:click="setActive(false)" wire:confirm="{{ __('Inaktivera stycklistan? Den kan inte väljas på nya offert- och orderrader, men rader som redan har en kopia behåller den.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Inaktivera stycklistan') }}</button>
                    @else
                        <button type="button" wire:click="setActive(true)" class="text-sm font-semibold text-accent-700 underline">{{ __('Använd stycklistan igen') }}</button>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
