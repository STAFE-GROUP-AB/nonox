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

        <x-ui.card :title="$activeReport !== null ? $activeReport->name : $dataset->label()" :description="__(':measure, :from till :to', ['measure' => $measure->label, 'from' => $definition->from->format('Y-m'), 'to' => $definition->to->format('Y-m')])">
            @if(! $result->tooLarge && $result->rows !== [])
                <x-slot:action>
                    <div class="flex flex-wrap items-center gap-3 text-sm" data-analysis-export>
                        <span class="text-gray-600">{{ __('Hämta som') }}</span>
                        <button type="button" wire:click="export('csv')" aria-label="{{ __('Hämta som :format', ['format' => 'CSV']) }}" wire:loading.attr="disabled" wire:target="export" class="font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">CSV</button>
                        <button type="button" wire:click="export('xlsx')" aria-label="{{ __('Hämta som :format', ['format' => 'Excel']) }}" wire:loading.attr="disabled" wire:target="export" class="font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">Excel</button>
                        <button type="button" wire:click="export('pdf')" aria-label="{{ __('Hämta som :format', ['format' => 'PDF']) }}" wire:loading.attr="disabled" wire:target="export" class="font-medium text-accent-600 hover:text-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">PDF</button>
                    </div>
                </x-slot:action>
            @endif
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

        <x-ui.card :title="__('Sparade rapporter')" :description="__('En sparad rapport kommer ihåg dina val, inte siffrorna. När någon öppnar den körs den på nytt med deras egen behörighet.')" data-saved-reports>
            <form wire:submit="saveReport" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field for="report_name" :label="__('Namn på rapporten')" error="reportName" class="lg:col-span-2">
                    <input id="report_name" type="text" maxlength="80" class="{{ $input }} mt-1" wire:model="reportName" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="report_visibility" :label="__('Vem ser rapporten?')" :hint="$canUpdateActive && ! $canShare && $activeReport->visibility === App\Domain\Analysis\Reports\ReportVisibility::Company ? __('Du kan inte längre dela rapporter. Uppdaterar du blir rapporten privat och försvinner från andras översikt.') : __('Namnet på en delad rapport syns för alla i företaget som kan använda Analys.')">
                    <select id="report_visibility" class="{{ $input }} mt-1" wire:model="reportVisibility">
                        <option value="PRIVATE">{{ App\Domain\Analysis\Reports\ReportVisibility::Private->label() }}</option>
                        @if($canShare)
                            <option value="COMPANY">{{ App\Domain\Analysis\Reports\ReportVisibility::Company->label() }}</option>
                        @endif
                    </select>
                </x-ui.field>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" class="rounded border-stone-300 text-accent-600 focus:ring-accent-500" wire:model="reportRolling">
                        {{ __('Rullande period, de senaste månaderna fram till idag') }}
                    </label>
                </div>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-4">
                    <x-button type="submit">{{ __('Spara som ny rapport') }}</x-button>
                    @if($canUpdateActive)
                        <x-secondary-button type="button" wire:click="updateReport">{{ __('Uppdatera :name', ['name' => $activeReport->name]) }}</x-secondary-button>
                    @endif
                </div>
            </form>

            @if($reports->isEmpty())
                <p class="mt-6 text-sm text-gray-600">{{ __('Du har inga sparade rapporter ännu. Ställ in en analys ovan och spara den med ett namn.') }}</p>
            @else
                <ul class="mt-6 divide-y divide-stone-100" data-report-list>
                    @foreach($reports as $report)
                        @php($mine = $report->owner_user_id === $userId)
                        @php($isCompany = $report->visibility === App\Domain\Analysis\Reports\ReportVisibility::Company)
                        @php($canPin = $mine ? (! $isCompany || $canShare) : ($isCompany && $canShare && ($report->pinned || $report->owner_user_id === null)))
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="report-{{ $report->id }}">
                            <div class="min-w-0">
                                <button type="button" wire:click="openReport({{ $report->id }})" class="font-semibold text-ink underline-offset-2 hover:underline">{{ $report->name }}</button>
                                <span class="ms-2 text-xs text-gray-600">{{ $report->visibility->label() }}@if(! $mine), {{ __('delad med dig') }}@endif</span>
                                @if($report->pinned)
                                    <x-ui.badge tone="neutral" class="ms-2">{{ __('På översikten') }}</x-ui.badge>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-4 text-sm">
                                @if($canPin)
                                    <button type="button" wire:click="pinReport({{ $report->id }}, {{ $report->pinned ? 'false' : 'true' }})" class="font-medium text-accent-600 hover:text-accent-700">{{ $report->pinned ? __('Ta bort från översikten') : __('Visa på översikten') }}<span class="sr-only"> {{ $report->name }}</span></button>
                                @endif
                                @if($mine || ($report->visibility === App\Domain\Analysis\Reports\ReportVisibility::Company && $canShare))
                                    <button type="button" wire:click="deleteReport({{ $report->id }})" wire:confirm="{{ __('Radera rapporten :name? Den försvinner för alla som kan se den. Bokföringen påverkas inte.', ['name' => $report->name]) }}" class="text-gray-600 underline">{{ __('Radera') }}<span class="sr-only"> {{ $report->name }}</span></button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if($listCut)
                    <p class="mt-3 text-sm text-gray-600" data-report-list-cut>{{ __('Bara de första :count rapporterna visas. Radera några du inte behöver för att se fler.', ['count' => App\Domain\Analysis\Reports\SavedReports::LIST_LIMIT]) }}</p>
                @endif
            @endif
        </x-ui.card>
    @endif
</div>
