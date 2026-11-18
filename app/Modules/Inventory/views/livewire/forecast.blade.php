@php
    use App\Modules\Inventory\Domain\ForecastPeriodKind;
    use App\Modules\Inventory\Domain\ForecastSource;
    use App\Modules\Inventory\Domain\PlanningMethod;
    use App\Modules\Inventory\Domain\Quantity;

    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $cell = 'w-20 rounded-md border-gray-300 px-2 py-1 text-right text-sm tabular-nums focus:border-accent-500 focus:ring-accent-500 disabled:bg-stone-50 disabled:text-gray-700';
    $isWeek = $kindEnum === ForecastPeriodKind::Week;
@endphp
<div class="space-y-6">
    <x-ui.card :description="__('Skriv in vad du räknar med att sälja eller använda, eller låt NONOX räkna fram ett genomsnitt ur lagrets historik. Varje värde går att ändra, och rutan visar om det är inskrivet eller beräknat.')">
        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="block grow sm:max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Sök nummer eller namn') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Period') }}</span>
                <select wire:model.live="kind" class="{{ $input }}">
                    <option value="vecka">{{ __('Per vecka') }}</option>
                    <option value="manad">{{ __('Per månad') }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ $isWeek ? __('Antal veckor (högst 52)') : __('Antal månader (högst 12)') }}</span>
                <input type="number" min="1" max="{{ $kindEnum->maxCount() }}" wire:model.live.debounce.500ms="periods" class="{{ $input }} w-28 text-right tabular-nums">
            </label>
        </div>

        @if($canRun)
            <div class="mb-6 flex flex-wrap items-end gap-4 rounded-2xl bg-stone-50 p-4">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">{{ __('Historik att räkna på, månader') }}</span>
                    <input type="number" min="1" max="24" wire:model.live.debounce.500ms="months" class="{{ $input }} w-28 text-right tabular-nums">
                </label>
                <label class="flex items-center gap-2 pb-2">
                    <input type="checkbox" wire:model="overwriteManual" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500">
                    <span class="text-sm text-gray-700">{{ __('Skriv över även inskrivna värden') }}</span>
                </label>
                <x-button type="button" wire:click="generate" wire:confirm="{{ __('Beräkna prognosen från historiken för alla artiklar i urvalet? Tidigare beräknade värden i perioderna skrivs över. Inskrivna värden behålls om du inte har markerat att de ska skrivas över.') }}" wire:loading.attr="disabled">{{ __('Beräkna från historik') }}</x-button>
                <p class="basis-full text-sm text-gray-600">{{ __('Genomsnittet av uttaget under de senaste :months hela månaderna (:from till :to): plock, kassaförsäljning, receptförsäljning och uttag till tillverkning.', ['months' => $window->months, 'from' => $window->from->toDateString(), 'to' => $window->lastDay()->toDateString()]) }}</p>
            </div>
        @endif

        @if($articles->isEmpty())
            <x-ui.empty-state :title="__('Inga lagerförda artiklar att prognostisera')">
                {{ __('Markera en artikel som lagerförd under Avancerat i artikelregistret. Sedan kan du lägga en prognos på den här.') }}
            </x-ui.empty-state>
        @else
            <p class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600">
                <span class="inline-flex items-center gap-2"><span class="inline-block size-4 rounded-sm bg-white ring-1 ring-stone-300" aria-hidden="true"></span>{{ __('Inskrivet') }}</span>
                <span class="inline-flex items-center gap-2"><span class="inline-block size-4 rounded-sm bg-sky-100 ring-1 ring-sky-300" aria-hidden="true"></span>{{ __('Beräknat från historik') }}</span>
            </p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th scope="col" class="sticky left-0 z-10 bg-white py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ $isWeek ? __('Snitt per vecka') : __('Snitt per månad') }}</th>
                            @foreach($columns as $period)
                                <th scope="col" class="py-2 pr-2 text-right font-medium tabular-nums">
                                    @if($isWeek)
                                        <span class="block">{{ __('v.') }}{{ $period->start->isoWeek() }}</span>
                                        <span class="block text-xs font-normal">{{ $period->start->locale('sv')->isoFormat('D MMM') }}</span>
                                    @else
                                        <span class="block">{{ $period->start->locale('sv')->isoFormat('MMM YYYY') }}</span>
                                    @endif
                                </th>
                            @endforeach
                            <th scope="col" class="py-2 pl-2 text-right font-medium">{{ __('Summa') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($articles as $article)
                            @php($rows = $forecasts[$article->id] ?? [])
                            @php($sum = array_sum(array_map(fn ($row) => $row->quantity_milli, $rows)))
                            <tr wire:key="forecast-article-{{ $article->id }}">
                                <th scope="row" class="sticky left-0 z-10 bg-white py-2 pr-4 text-left font-normal">
                                    <span class="font-semibold tabular-nums">{{ $article->number }}</span> {{ $article->name }}
                                    <span class="block text-xs text-gray-500">{{ $article->stockUnitCode() }}@if($article->planning_method !== PlanningMethod::None) · {{ $article->planning_method->label() }}@endif</span>
                                </th>
                                <td class="py-2 pr-4 text-right tabular-nums text-gray-700">{{ isset($perPeriod[$article->id]) ? Quantity::format($perPeriod[$article->id]) : '–' }}</td>
                                @foreach($columns as $period)
                                    @php($key = $period->start->toDateString())
                                    @php($row = $rows[$key] ?? null)
                                    <td class="py-2 pr-2 text-right">
                                        <input type="text" inputmode="decimal"
                                            value="{{ $row === null ? '' : Quantity::format($row->quantity_milli) }}"
                                            @if($canRun) wire:change="setCell({{ $article->id }}, '{{ $key }}', $event.target.value)" @else disabled @endif
                                            @class([$cell, 'bg-sky-100' => $row?->source === ForecastSource::History])
                                            aria-label="{{ __('Prognos för :article, :period', ['article' => $article->number, 'period' => $isWeek ? __('vecka :week', ['week' => $period->start->isoWeek()]) : $period->start->locale('sv')->isoFormat('MMMM YYYY')]) }}@if($row !== null), {{ $row->source->label() }}@endif"
                                            @if($row !== null) title="{{ $row->source->label() }}" @endif>
                                    </td>
                                @endforeach
                                <td class="py-2 pl-2 text-right font-medium tabular-nums">{{ $sum === 0 ? '–' : Quantity::format($sum) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $articles->links() }}</div>
        @endif
    </x-ui.card>
</div>
