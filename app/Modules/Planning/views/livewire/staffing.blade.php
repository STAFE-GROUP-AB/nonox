@php
    use App\Modules\Planning\Domain\WorkingTimeChecks;
    use App\Support\Money\Money;

    $locale = app()->getLocale();
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $money = fn (?int $ore): string => $ore === null ? '–' : Money::ofOre($ore)->format();
    $hours = fn (?int $minutes): string => $minutes === null ? '–' : WorkingTimeChecks::hours($minutes);
    $percent = fn (?float $value): string => $value === null ? '–' : str_replace('.', ',', (string) $value).' %';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500';
    $thNum = 'px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500';
    $td = 'px-3 py-2 text-gray-700';
    $tdNum = 'px-3 py-2 text-right tabular-nums text-gray-700';
    $byWeek = count($days) > 31;
    $points = $byWeek ? $weeks : $days;
    $pointLabel = fn (array $row): string => $byWeek ? __('v. :week', ['week' => $row['number']]) : \Carbon\CarbonImmutable::parse($row['date'])->locale($locale)->isoFormat('ddd D/M');
    $series = fn (string $key): array => array_map(fn (array $row) => $row[$key], $points);
    $chart = ['staffing' => [
        'labels' => array_map($pointLabel, $points),
        'planned' => $series('planned_cost'),
        'actual' => $has_actual ? $series('actual_cost') : null,
        'sales' => $series('sales'),
        'lastYearSales' => $has_last_year ? $series('last_year_sales') : null,
        'plannedPercent' => $series('planned_percent'),
        'actualPercent' => $has_actual ? $series('actual_percent') : null,
        'lastYearPercent' => $has_last_year ? $series('last_year_percent') : null,
    ]];
    $labels = [
        'planned' => __('Planerad kostnad (uppskattning)'),
        'actual' => __('Faktisk kostnad (uppskattning)'),
        'sales' => __('Försäljning i bokföringen'),
        'lastYearSales' => __('Försäljning samma vecka förra året'),
        'lastYear' => __('Förra året (planerat)'),
    ];
    $sourceLabels = ['attested' => __('attesterad tid'), 'ledger' => __('personalliggaren')];
    $presets = ['vecka' => __('Denna vecka'), 'forra-veckan' => __('Förra veckan'), 'fyra-veckor' => __('Fyra veckor'), 'manad' => __('Denna månad'), 'forra-manaden' => __('Förra månaden')];
@endphp

<div class="space-y-6">
    {{-- Period and filters --}}
    <div class="flex flex-wrap items-end justify-between gap-3 rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200">
        <div class="flex flex-wrap items-end gap-3">
            <button type="button" wire:click="previous" class="rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-stone-200 hover:bg-stone-50" aria-label="{{ __('Föregående period') }}">‹</button>
            <label class="block text-sm">
                <span class="font-medium text-gray-700">{{ __('Från') }}</span>
                <input type="date" wire:model.live="from" class="{{ $input }} mt-1 w-40">
            </label>
            <label class="block text-sm">
                <span class="font-medium text-gray-700">{{ __('Till') }}</span>
                <input type="date" wire:model.live="to" class="{{ $input }} mt-1 w-40">
            </label>
            <button type="button" wire:click="next" class="rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-stone-200 hover:bg-stone-50" aria-label="{{ __('Nästa period') }}">›</button>
            @if($areas->isNotEmpty())
                <label class="block text-sm">
                    <span class="font-medium text-gray-700">{{ __('Avdelning') }}</span>
                    <select wire:model.live="area" class="{{ $input }} mt-1 w-44">
                        <option value="">{{ __('Alla avdelningar') }}</option>
                        @foreach($areas as $one)
                            <option value="{{ $one->id }}">{{ $one->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @foreach($presets as $key => $label)
                <button type="button" wire:click="preset('{{ $key }}')" class="rounded-xl px-3 py-2 text-sm font-semibold text-accent-700 hover:bg-accent-50">{{ $label }}</button>
            @endforeach
            <a href="{{ $csvUrl }}" class="rounded-xl px-3 py-2 text-sm font-semibold ring-1 ring-stone-200 hover:bg-stone-50">{{ __('Ladda ner CSV') }}</a>
        </div>
    </div>

    {{-- Totals for the period --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Summa för perioden') }}">
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-600">{{ __('Planerad tid') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ $hours($totals['planned_minutes']) }}</p>
            <p class="text-sm text-gray-600">{{ __('Faktisk tid') }}: <span class="tabular-nums">{{ $hours($totals['actual_minutes']) }}</span></p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-600">{{ __('Planerad kostnad (uppskattning)') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ $money($totals['planned_cost']) }}{{ $totals['planned_incomplete'] ? ' *' : '' }}</p>
            <p class="text-sm text-gray-600">{{ __('Faktisk') }}: <span class="tabular-nums">{{ $money($totals['actual_cost']) }}{{ $totals['actual_incomplete'] ? ' *' : '' }}</span></p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-600">{{ __('Försäljning i bokföringen') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ $money($totals['sales']) }}</p>
            <p class="text-sm text-gray-600">{{ __('Förra året') }}: <span class="tabular-nums">{{ $money($totals['last_year_sales']) }}</span></p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-600">{{ __('Personalkostnad av försäljningen') }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ $percent($totals['planned_percent']) }}</p>
            <p class="text-sm text-gray-600">{{ __('Faktisk') }}: <span class="tabular-nums">{{ $percent($totals['actual_percent']) }}</span> · {{ __('Förra året') }}: <span class="tabular-nums">{{ $percent($totals['last_year_percent']) }}</span></p>
        </div>
    </section>

    {{-- What the figures are --}}
    <div class="space-y-2 rounded-2xl bg-paper px-4 py-3 text-sm text-gray-700">
        <p>{{ __('Kostnaderna är uppskattningar från passens klockslag och de anställdas löner, med OB, semesterersättning och arbetsgivaravgifter bara där reglerna är kontrollerade. * betyder att någon del saknas. Lönen räknas i lönekörningen.') }}</p>
        <p>{{ __('Försäljningen är bokförda verifikationer på konto :from–:to per verifikationsdatum, för hela företaget även när en avdelning är vald. Kontona ändras under Inställningar.', ['from' => $accounts[0], 'to' => $accounts[1]]) }}</p>
        <p>
            @if($actual_sources === [])
                {{ __('Faktisk tid visas när det finns attesterad tid eller när personalliggaren är påslagen. Inget av det finns för perioden.') }}
            @else
                {{ __('Faktisk tid kommer från :sources. Personalliggaren räknar tiden från incheckning till utcheckning, utan att dra av rast.', ['sources' => implode(__(' och '), array_map(fn ($key) => $sourceLabels[$key] ?? $key, $actual_sources))]) }}
            @endif
        </p>
        @if(! $has_last_year)
            <p>{{ __('Bokföringen har ingen försäljning och schemat inga pass för samma veckor förra året.') }}</p>
        @endif
        @foreach($notes as $note)
            <p>{{ $note }}</p>
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="grid gap-6 xl:grid-cols-2">
        <x-overview.chart kind="staffing" :title="$byWeek ? __('Personalkostnad och försäljning per vecka') : __('Personalkostnad och försäljning per dag')" :data="$chart" :labels="$labels"
            :summary="__('Planerad kostnad :planned, faktisk :actual, försäljning :sales för perioden.', ['planned' => $money($totals['planned_cost']), 'actual' => $money($totals['actual_cost']), 'sales' => $money($totals['sales'])])">
            <table class="min-w-full text-sm">
                <thead><tr>
                    <th scope="col" class="{{ $th }}">{{ $byWeek ? __('Vecka') : __('Dag') }}</th>
                    <th scope="col" class="{{ $thNum }}">{{ $labels['planned'] }}</th>
                    <th scope="col" class="{{ $thNum }}">{{ $labels['actual'] }}</th>
                    <th scope="col" class="{{ $thNum }}">{{ $labels['sales'] }}</th>
                    <th scope="col" class="{{ $thNum }}">{{ $labels['lastYearSales'] }}</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($points as $row)
                        <tr>
                            <th scope="row" class="{{ $td }} text-left font-medium">{{ $pointLabel($row) }}</th>
                            <td class="{{ $tdNum }}">{{ $money($row['planned_cost']) }}</td>
                            <td class="{{ $tdNum }}">{{ $money($row['actual_cost']) }}</td>
                            <td class="{{ $tdNum }}">{{ $money($row['sales']) }}</td>
                            <td class="{{ $tdNum }}">{{ $money($row['last_year_sales']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-overview.chart>

        <x-overview.chart kind="staffing-percent" :title="__('Personalkostnad i procent av försäljningen')" :data="$chart" :labels="$labels"
            :summary="__('Planerat :planned av försäljningen, faktiskt :actual, samma veckor förra året :last.', ['planned' => $percent($totals['planned_percent']), 'actual' => $percent($totals['actual_percent']), 'last' => $percent($totals['last_year_percent'])])">
            <table class="min-w-full text-sm">
                <thead><tr>
                    <th scope="col" class="{{ $th }}">{{ $byWeek ? __('Vecka') : __('Dag') }}</th>
                    <th scope="col" class="{{ $thNum }}">{{ __('Planerat') }}</th>
                    <th scope="col" class="{{ $thNum }}">{{ __('Faktiskt') }}</th>
                    <th scope="col" class="{{ $thNum }}">{{ __('Förra året') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($points as $row)
                        <tr>
                            <th scope="row" class="{{ $td }} text-left font-medium">{{ $pointLabel($row) }}</th>
                            <td class="{{ $tdNum }}">{{ $percent($row['planned_percent']) }}</td>
                            <td class="{{ $tdNum }}">{{ $percent($row['actual_percent']) }}</td>
                            <td class="{{ $tdNum }}">{{ $percent($row['last_year_percent']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-overview.chart>
    </div>

    {{-- Per week, then per day --}}
    <x-ui.card :title="__('Per vecka och dag')">
        <div class="-mx-2 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200">
                        <th scope="col" class="{{ $th }}">{{ __('Period') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Planerad tid') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Planerad kostnad') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Faktisk tid') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Faktisk kostnad') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Försäljning') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Andel planerad') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Andel faktisk') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Försäljning förra året') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ __('Andel förra året') }}</th>
                    </tr>
                </thead>
                @foreach($weeks as $week)
                    <tbody class="divide-y divide-gray-100" wire:key="week-{{ $week['week'] }}">
                        <tr class="bg-stone-50 font-semibold text-ink">
                            <th scope="row" class="{{ $td }} text-left font-semibold text-ink">{{ __('Vecka :week', ['week' => $week['number']]) }}</th>
                            <td class="{{ $tdNum }}">{{ $hours($week['planned_minutes']) }}</td>
                            <td class="{{ $tdNum }}">{{ $money($week['planned_cost']) }}{{ $week['planned_incomplete'] ? ' *' : '' }}</td>
                            <td class="{{ $tdNum }}">{{ $hours($week['actual_minutes']) }}</td>
                            <td class="{{ $tdNum }}">{{ $money($week['actual_cost']) }}{{ $week['actual_incomplete'] ? ' *' : '' }}</td>
                            <td class="{{ $tdNum }}">{{ $money($week['sales']) }}</td>
                            <td class="{{ $tdNum }}">{{ $percent($week['planned_percent']) }}</td>
                            <td class="{{ $tdNum }}">{{ $percent($week['actual_percent']) }}</td>
                            <td class="{{ $tdNum }}">{{ $money($week['last_year_sales']) }}</td>
                            <td class="{{ $tdNum }}">{{ $percent($week['last_year_percent']) }}</td>
                        </tr>
                        @foreach($days as $day)
                            @continue($day['week'] !== $week['week'])
                            <tr>
                                <th scope="row" class="{{ $td }} text-left font-normal">{{ ucfirst(\Carbon\CarbonImmutable::parse($day['date'])->locale($locale)->isoFormat('dddd D MMM')) }}</th>
                                <td class="{{ $tdNum }}">{{ $hours($day['planned_minutes']) }}</td>
                                <td class="{{ $tdNum }}">{{ $money($day['planned_cost']) }}{{ $day['planned_incomplete'] ? ' *' : '' }}</td>
                                <td class="{{ $tdNum }}" title="{{ $day['actual_source'] ? __('Från :source', ['source' => $sourceLabels[$day['actual_source']] ?? '']) : '' }}">{{ $hours($day['actual_minutes']) }}</td>
                                <td class="{{ $tdNum }}">{{ $money($day['actual_cost']) }}{{ $day['actual_incomplete'] ? ' *' : '' }}</td>
                                <td class="{{ $tdNum }}">{{ $money($day['sales']) }}</td>
                                <td class="{{ $tdNum }}">{{ $percent($day['planned_percent']) }}</td>
                                <td class="{{ $tdNum }}">{{ $percent($day['actual_percent']) }}</td>
                                <td class="{{ $tdNum }}" title="{{ $day['last_year_date'] }}">{{ $money($day['last_year_sales']) }}</td>
                                <td class="{{ $tdNum }}">{{ $percent($day['last_year_percent']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
                <tfoot>
                    <tr class="border-t-2 border-stone-300 font-bold text-ink">
                        <th scope="row" class="{{ $td }} text-left font-bold text-ink">{{ __('Summa') }}</th>
                        <td class="{{ $tdNum }}">{{ $hours($totals['planned_minutes']) }}</td>
                        <td class="{{ $tdNum }}">{{ $money($totals['planned_cost']) }}</td>
                        <td class="{{ $tdNum }}">{{ $hours($totals['actual_minutes']) }}</td>
                        <td class="{{ $tdNum }}">{{ $money($totals['actual_cost']) }}</td>
                        <td class="{{ $tdNum }}">{{ $money($totals['sales']) }}</td>
                        <td class="{{ $tdNum }}">{{ $percent($totals['planned_percent']) }}</td>
                        <td class="{{ $tdNum }}">{{ $percent($totals['actual_percent']) }}</td>
                        <td class="{{ $tdNum }}">{{ $money($totals['last_year_sales']) }}</td>
                        <td class="{{ $tdNum }}">{{ $percent($totals['last_year_percent']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-ui.card>
</div>
