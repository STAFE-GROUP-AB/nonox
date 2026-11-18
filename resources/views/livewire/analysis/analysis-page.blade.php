@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $num = 'px-3 py-2 text-right tabular-nums whitespace-nowrap';
@endphp
<div class="space-y-6">
    @if($dataset === null)
        <x-ui.card>
            <x-ui.empty-state :title="__('Ingen uppsättning att visa')">
                {{ __('Din roll har inte behörighet till någon av uppsättningarna.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @else
        @php
            $format = fn (int $value): string => $measure->kind === App\Domain\Analysis\Measure::MONEY
                ? App\Support\Money\Money::ofOre($value)->format()
                : number_format($value, 0, ',', "\u{00A0}");
            $tone = fn (int $value): string => $measure->kind === App\Domain\Analysis\Measure::MONEY && $value < 0 ? 'text-accent-700' : '';
            $hiddenText = __('Dold: färre än :count personer', ['count' => App\Domain\Analysis\Datasets\PayrollDataset::MIN_PEOPLE]);
            $columnsCount = count($result->columns) + 1 + ($definition->column !== null ? 1 : 0);
        @endphp

        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-analysis-choices>
                <x-ui.field for="analysis_dataset" :label="__('Uppgifter')" class="sm:col-span-2">
                    <select id="analysis_dataset" class="{{ $input }} mt-1" wire:model.live="source">
                        @foreach($datasets as $key => $option)
                            <option value="{{ $key }}" @selected($dataset->key() === $key)>{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-sm text-gray-500">{{ $dataset->description() }}</p>
                </x-ui.field>
                <x-ui.field for="analysis_measure" :label="__('Mått')">
                    <select id="analysis_measure" class="{{ $input }} mt-1" wire:model.live="metric">
                        @foreach($measures as $key => $option)
                            <option value="{{ $key }}" @selected($definition->measure === $key)>{{ $option->label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="analysis_sort" :label="__('Sortera')">
                    <select id="analysis_sort" class="{{ $input }} mt-1" wire:model.live="sort">
                        <option value="label" @selected($definition->sort === 'label')>{{ __('Efter namn') }}</option>
                        <option value="total" @selected($definition->sort === 'total')>{{ __('Störst summa först') }}</option>
                    </select>
                </x-ui.field>

                @foreach([0, 1] as $slot)
                    <x-ui.field :for="'analysis_row_'.$slot" :label="$slot === 0 ? __('Rader') : __('Rader, nivå två')">
                        <select id="analysis_row_{{ $slot }}" class="{{ $input }} mt-1" wire:model.live="rows.{{ $slot }}">
                            <option value="">{{ $slot === 0 ? __('Välj') : __('Ingen') }}</option>
                            @foreach($dimensions as $key => $dimension)
                                <option value="{{ $key }}" @selected(($definition->rows[$slot] ?? '') === $key)>{{ $dimension->label }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endforeach
                <x-ui.field for="analysis_column" :label="__('Kolumner')">
                    <select id="analysis_column" class="{{ $input }} mt-1" wire:model.live="column">
                        <option value="">{{ __('Ingen') }}</option>
                        @foreach($dimensions as $key => $dimension)
                            <option value="{{ $key }}" @selected($definition->column === $key)>{{ $dimension->label }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" class="rounded border-stone-300 text-accent-600 focus:ring-accent-500" wire:model.live="compare">
                        {{ __('Jämför med samma månader året innan') }}
                    </label>
                </div>

                <x-ui.field for="analysis_from" :label="__('Från månad')">
                    <input id="analysis_from" type="month" class="{{ $input }} mt-1" wire:model.live.debounce.500ms="from" value="{{ $definition->from->format('Y-m') }}">
                </x-ui.field>
                <x-ui.field for="analysis_to" :label="__('Till månad')">
                    <input id="analysis_to" type="month" class="{{ $input }} mt-1" wire:model.live.debounce.500ms="to" value="{{ $definition->to->format('Y-m') }}">
                </x-ui.field>

                @foreach([1, 2] as $slot)
                    @php($property = 'filterDimension'.$slot)
                    <x-ui.field :for="'analysis_filter_'.$slot" :label="__('Filter :number', ['number' => $slot])">
                        <select id="analysis_filter_{{ $slot }}" class="{{ $input }} mt-1" wire:model.live="{{ $property }}">
                            <option value="">{{ __('Inget filter') }}</option>
                            @foreach($dimensions as $key => $dimension)
                                @continue($key === 'period')
                                <option value="{{ $key }}">{{ $dimension->label }}</option>
                            @endforeach
                        </select>
                        @if($filterOptions[$slot] !== [])
                            <select class="{{ $input }} mt-2" multiple size="4" aria-label="{{ __('Värden för filter :number', ['number' => $slot]) }}" wire:model.live="filterValues{{ $slot }}">
                                @foreach($filterOptions[$slot] as $value => $text)
                                    <option value="{{ $value }}">{{ $text }}</option>
                                @endforeach
                            </select>
                        @endif
                    </x-ui.field>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card :title="$dataset->label()" :description="__(':measure, :from till :to', ['measure' => $measure->label, 'from' => $definition->from->format('Y-m'), 'to' => $definition->to->format('Y-m')])">
            @if($result->tooLarge)
                <x-ui.empty-state :title="__('För många celler')">
                    {{ __('Tabellen skulle få fler än :count celler. Välj färre rader eller kolumner, eller lägg på ett filter.', ['count' => number_format(App\Domain\Analysis\AnalysisRunner::CELL_CAP, 0, ',', "\u{00A0}")]) }}
                </x-ui.empty-state>
            @elseif($result->rows === [])
                <x-ui.empty-state :title="__('Inga poster att visa')">
                    {{ $limited
                        ? __('Du ser bara det som hör till de kostnadsställen och projekt du har behörighet till.')
                        : __('Inga poster för valet. Prova en annan period eller ta bort ett filter.') }}
                </x-ui.empty-state>
            @else
                <div class="overflow-x-auto" data-analysis-table>
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-right text-gray-500">
                                <th scope="col" class="py-2 pe-3 text-left font-medium" @if(count($definition->rows) > 1) data-two-levels @endif>
                                    {{ collect($definition->rows)->map(fn ($key) => $dimensions[$key]->label)->implode(' / ') }}
                                </th>
                                @foreach($result->columns as $column)
                                    <th scope="col" class="px-3 py-2 font-medium">{{ $column['label'] !== '' ? $column['label'] : $measure->label }}</th>
                                @endforeach
                                @if($definition->column !== null)
                                    <th scope="col" class="px-3 py-2 font-medium">{{ __('Summa') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($result->rows as $row)
                                <tr wire:key="analysis-row-{{ md5($row['key']) }}">
                                    <th scope="row" class="py-2 pe-3 text-left font-normal">{{ $row['label'] }}</th>
                                    @foreach($result->columns as $column)
                                        @php($value = $result->cells[$row['key']][$column['key']] ?? null)
                                        <td class="{{ $num }}">
                                            @if($value === null)
                                                <span class="text-gray-400">–</span>
                                            @elseif($value['hidden'])
                                                <span class="text-gray-500" title="{{ $hiddenText }}" data-hidden-cell>{{ __('Dold') }}</span>
                                            @else
                                                @if($dataset->drillable())
                                                    <button type="button" wire:click="drill({{ \Illuminate\Support\Js::from($row['key']) }}, {{ \Illuminate\Support\Js::from($column['key']) }})" class="underline-offset-2 hover:underline {{ $tone($value['v']) }}">{{ $format($value['v']) }}</button>
                                                @else
                                                    <span class="{{ $tone($value['v']) }}">{{ $format($value['v']) }}</span>
                                                @endif
                                                @if($result->compared)
                                                    <span class="block text-xs text-gray-500">
                                                        @if($value['hiddenPrevious'])
                                                            {{ __('Förra året: dold') }}
                                                        @else
                                                            {{ __('Förra året: :amount', ['amount' => $format($value['p'])]) }}
                                                            <span class="{{ $tone($value['v'] - $value['p']) }}">({{ $format($value['v'] - $value['p']) }})</span>
                                                        @endif
                                                    </span>
                                                @endif
                                            @endif
                                        </td>
                                    @endforeach
                                    @if($definition->column !== null)
                                        @php($total = $result->rowTotals[$row['key']])
                                        <td class="{{ $num }} font-semibold">
                                            @if($total['hidden'])
                                                <span class="text-gray-500" title="{{ $hiddenText }}">{{ __('Dold') }}</span>
                                            @else
                                                <span class="{{ $tone($total['v']) }}">{{ $format($total['v']) }}</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                                @if($drill !== null && $this->drillRow === $row['key'])
                                    <tr>
                                        <td colspan="{{ $columnsCount }}" class="bg-stone-50 px-3 py-3" data-analysis-drill>
                                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                <p class="font-semibold text-ink">{{ __('Poster bakom :row', ['row' => $row['label'].($this->drillColumn !== '' ? ' / '.collect($result->columns)->firstWhere('key', $this->drillColumn)['label'] : '')]) }}</p>
                                                <div class="flex gap-4">
                                                    @if($dataset->listRoute() !== null)
                                                        <a href="{{ route($dataset->listRoute()) }}" wire:navigate class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna listan') }}</a>
                                                    @endif
                                                    <button type="button" wire:click="closeDrill" class="text-sm text-gray-600 underline">{{ __('Stäng') }}</button>
                                                </div>
                                            </div>
                                            @if($drill['rows'] === [])
                                                <p class="mt-2 text-sm text-gray-600">{{ __('Inga poster.') }}</p>
                                            @else
                                                <div class="mt-2 overflow-x-auto">
                                                    <table class="min-w-full text-sm">
                                                        <thead>
                                                            <tr class="text-gray-500">
                                                                @foreach($drill['columns'] as $heading)
                                                                    <th scope="col" class="px-3 py-1 text-left font-medium">{{ $heading }}</th>
                                                                @endforeach
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-stone-200">
                                                            @foreach(array_slice($drill['rows'], 0, $drillLimit) as $line)
                                                                <tr>
                                                                    @foreach($line['cells'] as $index => $text)
                                                                        <td class="px-3 py-1 {{ $index === array_key_last($line['cells']) ? 'text-right tabular-nums whitespace-nowrap' : '' }}">
                                                                            @if($index === 1 && $line['url'] !== null)
                                                                                <a href="{{ $line['url'] }}" wire:navigate class="text-accent-600 hover:text-accent-700">{{ $text }}</a>
                                                                            @else
                                                                                {{ $text }}
                                                                            @endif
                                                                        </td>
                                                                    @endforeach
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                                @if(count($drill['rows']) > $drillLimit)
                                                    <p class="mt-2 text-sm text-gray-600">{{ __('Visar de första :count posterna. Välj ett smalare val för att se fler.', ['count' => $drillLimit]) }}</p>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-stone-300 font-semibold">
                                <th scope="row" class="py-2 pe-3 text-left">{{ __('Summa') }}</th>
                                @foreach($result->columns as $column)
                                    @php($total = $result->columnTotals[$column['key']] ?? null)
                                    <td class="{{ $num }}">
                                        @if($total !== null && $total['hidden'])
                                            <span class="text-gray-500" title="{{ $hiddenText }}">{{ __('Dold') }}</span>
                                        @elseif($total !== null)
                                            <span class="{{ $tone($total['v']) }}">{{ $format($total['v']) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                @if($definition->column !== null)
                                    <td class="{{ $num }}">
                                        @if($result->grandTotal['hidden'])
                                            <span class="text-gray-500" title="{{ $hiddenText }}">{{ __('Dold') }}</span>
                                        @else
                                            <span class="{{ $tone($result->grandTotal['v']) }}">{{ $format($result->grandTotal['v']) }}</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @if($dataset->key() === 'payroll')
                    <p class="mt-3 text-sm text-gray-600" data-payroll-note>{{ __('Lön visas bara sammanräknad, aldrig per person. Ett belopp döljs när något av dess delar, per lönekod, kostnadsställe och månad, gäller färre än :count personer eller domineras av en person; summor som innehåller en dold del döljs också.', ['count' => App\Domain\Analysis\Datasets\PayrollDataset::MIN_PEOPLE]) }}</p>
                @endif
                @if($limited)
                    <p class="mt-3 text-sm text-gray-600" data-scope-note>{{ __('Du ser bara rader på dina egna kostnadsställen och projekt. Personalkostnader (konto 7000–7699) och lönebokföring visas inte, och andra enheter visas som Annan enhet.') }}</p>
                @endif
            @endif
        </x-ui.card>
    @endif
</div>
