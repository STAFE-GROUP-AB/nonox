@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($money = fn (int $ore) => App\Support\Money\Money::ofOre($ore))
@php($qty = fn (int $milli) => App\Modules\Inventory\Domain\Quantity::format($milli))
@php($pct = fn (?int $bp) => App\Modules\Manufacturing\Livewire\RowStructuresPanel::percent($bp))
@php($duration = fn (int $minutes) => App\Modules\Manufacturing\Livewire\RowStructuresPanel::duration($minutes))
<div>
    <x-ui.card :title="__('Stycklistor och kalkyl')" :description="__('Material, arbete och marginal per rad med stycklista. Visas bara här, aldrig på kundens PDF. Kalkylen räknas på de sparade raderna.')">
        <x-input-error for="panel.structure" class="mb-3" />

        @if($rows->isEmpty())
            <x-ui.empty-state :title="__('Inga sparade rader')">{{ __('Spara dokumentet med minst en rad, så kan raden få en stycklista.') }}</x-ui.empty-state>
        @else
            <div class="space-y-4">
                @foreach($rows as $row)
                    @php($structure = $row->line_key ? $structures->get($row->line_key) : null)
                    @php($line = $row->line_key ? ($costing->rows[$row->line_key] ?? null) : null)
                    <section class="rounded-2xl ring-1 ring-stone-200 p-4" wire:key="row-structure-{{ $row->line_key ?? $row->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <h3 class="text-sm font-semibold text-ink">
                                {{ __('Rad :line', ['line' => $row->position + 1]) }} · {{ $row->description }}
                                <span class="font-normal text-gray-500">· {{ str_replace('.', ',', rtrim(rtrim($row->quantity, '0'), '.')) }} {{ $row->unit }} · <x-ui.amount :money="$row->net_ore" /></span>
                            </h3>
                            @if($structure && $editable && $editingKey !== $row->line_key)
                                <div class="flex flex-wrap gap-4 text-sm">
                                    <button type="button" wire:click="edit('{{ $row->line_key }}')" class="font-semibold text-accent-700 underline">{{ __('Ändra för raden') }}</button>
                                    @if($canManage)
                                        <button type="button" wire:click="startSaveAs('{{ $row->line_key }}')" class="font-semibold text-gray-700 underline">{{ __('Spara som ny stycklista') }}</button>
                                    @endif
                                    <button type="button" wire:click="remove('{{ $row->line_key }}')" wire:confirm="{{ __('Ta bort stycklistan från raden? Ändringarna som gjorts på raden försvinner; mallen finns kvar.') }}" class="font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                                </div>
                            @endif
                        </div>

                        @if($structure === null)
                            @if($row->line_key === null)
                                <p class="mt-2 text-sm text-gray-600">{{ __('Spara dokumentet en gång till, så kan raden få en stycklista.') }}</p>
                            @elseif(! $editable)
                                <p class="mt-2 text-sm text-gray-600">{{ __('Ingen stycklista.') }}</p>
                            @elseif($bomArticles->isEmpty())
                                <p class="mt-2 text-sm text-gray-600">{{ __('Det finns inga stycklistor ännu.') }} <a href="{{ route('manufacturing.boms.index') }}" class="font-semibold text-accent-700 underline">{{ __('Gå till Stycklistor') }}</a></p>
                            @else
                                <div class="mt-3 flex flex-wrap items-end gap-3">
                                    <label class="block min-w-[16rem]">
                                        <span class="text-sm font-medium text-gray-700">{{ __('Stycklista') }}</span>
                                        <select wire:model="choice.{{ $row->line_key }}" class="{{ $input }}">
                                            <option value="">{{ __('Välj stycklista') }}</option>
                                            @foreach($bomArticles as $option)
                                                <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <x-secondary-button type="button" wire:click="choose('{{ $row->line_key }}')" wire:loading.attr="disabled">{{ __('Hämta stycklistan') }}</x-secondary-button>
                                </div>
                            @endif
                        @else
                            <p class="mt-1 text-xs text-gray-500">
                                {{ $structure->name }}
                                @if($structure->source) · {{ __('kopierad från version :version', ['version' => $structure->source->version]) }} @endif
                                @if($structure->changed) · <span class="font-semibold text-accent-700">{{ __('ändrad för raden') }}</span> @endif
                                · {{ __('gäller för :yield :unit', ['yield' => $qty($structure->yield_milli), 'unit' => $structure->outputArticle?->stockUnit?->code ?? $structure->outputArticle?->unit]) }}
                            </p>

                            @if($editingKey === $row->line_key)
                                <form wire:submit="update" class="mt-4 grid gap-4 sm:grid-cols-6">
                                    <x-ui.field for="copy-name" :label="__('Namn')" error="form.name" class="sm:col-span-4">
                                        <input id="copy-name" wire:model="form.name" maxlength="255" class="{{ $input }}">
                                    </x-ui.field>
                                    <x-ui.field for="copy-yield" :label="__('Gäller för antal')" error="form.yield" class="sm:col-span-2">
                                        <input id="copy-yield" wire:model="form.yield" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                    </x-ui.field>
                                    <x-input-error for="form.lines" class="sm:col-span-6" />
                                    <div class="space-y-3 sm:col-span-6">
                                        @foreach($form['lines'] as $index => $item)
                                            <div class="grid gap-3 rounded-2xl bg-stone-50 p-3 sm:grid-cols-12" wire:key="copy-line-{{ $index }}">
                                                @if(($item['type'] ?? 'component') === 'operation')
                                                    <x-ui.field for="copy-{{ $index }}-operation" :label="__('Arbetssteg')" error="form.lines.{{ $index }}.operation_id" class="sm:col-span-6">
                                                        <select id="copy-{{ $index }}-operation" wire:model="form.lines.{{ $index }}.operation_id" class="{{ $input }}">
                                                            <option value="">{{ __('Välj arbetssteg') }}</option>
                                                            @foreach($activeOperations as $option)
                                                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                                                            @endforeach
                                                            @if(($item['operation_id'] ?? '') !== '' && ! $activeOperations->contains('id', (int) $item['operation_id']))
                                                                <option value="{{ $item['operation_id'] }}">{{ $operations->get((int) $item['operation_id'])?->name }} ({{ __('arkiverat') }})</option>
                                                            @endif
                                                        </select>
                                                    </x-ui.field>
                                                    <x-ui.field for="copy-{{ $index }}-minutes" :label="__('Minuter')" error="form.lines.{{ $index }}.minutes" class="sm:col-span-2">
                                                        <input id="copy-{{ $index }}-minutes" wire:model="form.lines.{{ $index }}.minutes" inputmode="numeric" class="{{ $input }} text-right tabular-nums">
                                                    </x-ui.field>
                                                    <div class="sm:col-span-2"></div>
                                                @else
                                                    <x-ui.field for="copy-{{ $index }}-article" :label="__('Komponent')" error="form.lines.{{ $index }}.article_id" class="sm:col-span-4">
                                                        <select id="copy-{{ $index }}-article" wire:model.live="form.lines.{{ $index }}.article_id" class="{{ $input }}">
                                                            <option value="">{{ __('Välj artikel') }}</option>
                                                            @foreach($articles as $option)
                                                                <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }} ({{ $option->stockUnit?->code ?? $option->unit }})</option>
                                                            @endforeach
                                                        </select>
                                                    </x-ui.field>
                                                    <x-ui.field for="copy-{{ $index }}-quantity" :label="__('Nettomängd')" error="form.lines.{{ $index }}.quantity" class="sm:col-span-2">
                                                        <input id="copy-{{ $index }}-quantity" wire:model="form.lines.{{ $index }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                                    </x-ui.field>
                                                    <x-ui.field for="copy-{{ $index }}-unit" :label="__('Enhet')" error="form.lines.{{ $index }}.unit" class="sm:col-span-2">
                                                        <select id="copy-{{ $index }}-unit" wire:model="form.lines.{{ $index }}.unit" class="{{ $input }}">
                                                            @foreach($units[(int) $item['article_id']] ?? [] as $unit)
                                                                <option value="{{ $loop->first ? '' : $unit }}">{{ $unit }}</option>
                                                            @endforeach
                                                        </select>
                                                    </x-ui.field>
                                                    <x-ui.field for="copy-{{ $index }}-loss" :label="__('Spill %')" error="form.lines.{{ $index }}.loss" class="sm:col-span-2">
                                                        <input id="copy-{{ $index }}-loss" wire:model="form.lines.{{ $index }}.loss" inputmode="decimal" class="{{ $input }} text-right tabular-nums" placeholder="0">
                                                    </x-ui.field>
                                                @endif
                                                <div class="flex items-end sm:col-span-2">
                                                    <button type="button" wire:click="removeLine({{ $index }})" class="text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="flex flex-wrap gap-4 sm:col-span-6">
                                        <button type="button" wire:click="addLine('component')" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till komponent') }}</button>
                                        <button type="button" wire:click="addLine('operation')" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till arbetssteg') }}</button>
                                    </div>
                                    <div class="flex flex-wrap gap-3 sm:col-span-6">
                                        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara radens stycklista') }}</x-button>
                                        <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                                    </div>
                                </form>
                            @else
                                <div class="mt-3 overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-stone-200 text-left text-gray-500">
                                                <th class="py-1.5 pr-4 font-medium">{{ __('Komponent eller arbetssteg') }}</th>
                                                <th class="py-1.5 pr-4 text-right font-medium">{{ __('Mängd eller tid') }}</th>
                                                <th class="py-1.5 pr-4 text-right font-medium">{{ __('Spill') }}</th>
                                                <th class="py-1.5 pr-4 text-right font-medium">{{ __('Material') }}</th>
                                                <th class="py-1.5 pr-4 text-right font-medium">{{ __('Arbete') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-stone-100">
                                            @foreach($line['costing']->rows as $item)
                                                @php($part = $item['line'])
                                                <tr wire:key="copy-row-{{ $part->id }}">
                                                    @if($part->operation_id !== null)
                                                        <td class="py-1.5 pr-4">{{ $item['operation']?->name ?? __('Arbetssteg') }}</td>
                                                        <td class="py-1.5 pr-4 text-right tabular-nums">{{ $duration((int) $part->minutes) }}</td>
                                                        <td class="py-1.5 pr-4 text-right">–</td>
                                                        <td class="py-1.5 pr-4 text-right">–</td>
                                                        <td class="py-1.5 pr-4 text-right">@if($item['labour_ore'] !== null)<x-ui.amount :money="$money($item['labour_ore'])" />@else – @endif</td>
                                                    @else
                                                        @php($unit = $part->component?->stockUnit?->code ?? $part->component?->unit)
                                                        <td class="py-1.5 pr-4">{{ $part->component?->name }}</td>
                                                        <td class="py-1.5 pr-4 text-right tabular-nums">
                                                            @if($part->entered_unit){{ str_replace('.', ',', (string) $part->entered_quantity) }} {{ $part->entered_unit }} <span class="text-xs text-gray-500">(≈ {{ $qty($part->quantity_milli) }} {{ $unit }})</span>@else{{ $qty($part->quantity_milli) }} {{ $unit }}@endif
                                                        </td>
                                                        <td class="py-1.5 pr-4 text-right tabular-nums">{{ $pct($part->loss_bp) }}</td>
                                                        <td class="py-1.5 pr-4 text-right">@if($item['material_ore'] !== null)<x-ui.amount :money="$money($item['material_ore'])" />@else<span class="text-accent-700">{{ __('Saknar pris') }}</span>@endif</td>
                                                        <td class="py-1.5 pr-4 text-right">@if($item['labour_ore'])<x-ui.amount :money="$money($item['labour_ore'])" />@else – @endif</td>
                                                    @endif
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                @include('manufacturing::livewire.partials.costing-summary', ['costing' => $line['costing'], 'unit' => $structure->outputArticle?->stockUnit?->code ?? $structure->outputArticle?->unit])

                                <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                                    <div class="rounded-2xl bg-stone-50 p-4">
                                        <dt class="text-sm text-gray-500">{{ __('Kostnad för raden') }}</dt>
                                        <dd class="mt-1 text-lg font-bold">
                                            @if($line['cost_ore'] !== null)
                                                <x-ui.amount :money="$money($line['cost_ore'])" />
                                            @elseif($line['quantity_milli'] === null)
                                                <span class="text-accent-700">{{ __('Radens enhet :unit går inte att räkna om till :stock.', ['unit' => $row->unit, 'stock' => $structure->outputArticle?->stockUnit?->code ?? $structure->outputArticle?->unit]) }}</span>
                                            @else
                                                <span class="text-accent-700">{{ __('Ofullständig') }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="rounded-2xl bg-stone-50 p-4">
                                        <dt class="text-sm text-gray-500">{{ __('Täckningsbidrag') }}</dt>
                                        <dd class="mt-1 text-lg font-bold">@if($line['contribution_ore'] !== null)<x-ui.amount :money="$money($line['contribution_ore'])" />@else – @endif</dd>
                                        <dd class="text-xs text-gray-500">{{ __('Radens belopp :net exklusive moms', ['net' => $money($line['net_ore'])->format()]) }}</dd>
                                    </div>
                                    <div class="rounded-2xl bg-stone-50 p-4">
                                        <dt class="text-sm text-gray-500">{{ __('Marginal') }}</dt>
                                        <dd class="mt-1 text-lg font-bold {{ ($line['margin_bp'] ?? 0) < 0 ? 'text-accent-700' : '' }}">{{ $pct($line['margin_bp']) }}</dd>
                                    </div>
                                </dl>
                            @endif

                            @if($savingAsKey === $row->line_key)
                                <form wire:submit="saveAsBillOfMaterials" class="mt-4 grid gap-4 rounded-2xl bg-stone-50 p-4 sm:grid-cols-6">
                                    <p class="text-sm text-gray-700 sm:col-span-6">{{ __('Sparar radens stycklista som en ny version för produkten nedan, eller som en ny stycklista om produkten inte har någon. Raden behåller sin kopia.') }}</p>
                                    <x-ui.field for="save-as-output" :label="__('Produkt')" error="saveAs.output_article_id" class="sm:col-span-3">
                                        <select id="save-as-output" wire:model="saveAs.output_article_id" class="{{ $input }}">
                                            @foreach($outputArticles as $option)
                                                <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                    <x-ui.field for="save-as-name" :label="__('Namn')" error="saveAs.name" class="sm:col-span-3">
                                        <input id="save-as-name" wire:model="saveAs.name" maxlength="255" class="{{ $input }}">
                                    </x-ui.field>
                                    <x-ui.field for="save-as-valid-from" :label="__('Gäller från')" error="saveAs.valid_from" class="sm:col-span-2">
                                        <input id="save-as-valid-from" type="date" wire:model.live="saveAs.valid_from" class="{{ $input }}">
                                    </x-ui.field>
                                    @if(($saveAs['valid_from'] ?? '') !== '' && ($saveAs['valid_from'] ?? '') < now()->toDateString() || $errors->has('saveAs.valid_from'))
                                        <label class="flex items-start gap-3 sm:col-span-6">
                                            <input type="checkbox" wire:model="saveAs.confirm_backdate" class="mt-1 rounded-sm border-stone-300 text-ink focus:ring-accent-500">
                                            <span class="text-sm text-gray-700">{{ __('Stycklistan ska gälla bakåt i tiden.') }}</span>
                                        </label>
                                    @endif
                                    <div class="flex flex-wrap gap-3 sm:col-span-6">
                                        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara som ny stycklista') }}</x-button>
                                        <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                                    </div>
                                </form>
                            @endif
                        @endif
                    </section>
                @endforeach
            </div>

            @if($costing->rows !== [])
                <dl class="mt-6 grid gap-3 border-t border-stone-200 pt-5 sm:grid-cols-4">
                    <div>
                        <dt class="text-sm text-gray-500">{{ __('Belopp, rader med stycklista') }}</dt>
                        <dd class="mt-1 text-lg font-bold"><x-ui.amount :money="$money($costing->netOre)" /></dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ __('Kostnad') }}</dt>
                        <dd class="mt-1 text-lg font-bold"><x-ui.amount :money="$money($costing->costOre)" /></dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ __('Täckningsbidrag') }}</dt>
                        <dd class="mt-1 text-lg font-bold"><x-ui.amount :money="$money($costing->contributionOre())" /></dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">{{ __('Marginal') }}</dt>
                        <dd class="mt-1 text-lg font-bold">{{ $pct($costing->marginBp()) }}</dd>
                    </div>
                </dl>
                <p class="mt-2 text-sm text-gray-600">
                    {{ __('Summan gäller raderna med stycklista. Rader utan stycklista har ingen kostnad här.') }}
                    @unless($costing->complete) {{ __('En rad saknar pris och räknas inte med.') }} @endunless
                </p>
            @endif
        @endif
    </x-ui.card>
</div>
