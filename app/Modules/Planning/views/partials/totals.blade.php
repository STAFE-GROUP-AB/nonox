{{-- The footer of the grid: per day planned hours, estimated cost, sales from the books (or the forecast, ≈), labour cost as a share of sales and the staffing demand. --}}
@php($demandText = \App\Modules\Planning\Support\Demand\DemandText::class)
<div class="sticky left-0 z-10 bg-stone-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500" role="rowheader">{{ __('Summa') }}</div>
@foreach($days as $day)
    @php($total = $totals[$day->toDateString()])
    @php($need = ($demand ?? [])[$day->toDateString()] ?? null)
    <div class="border-l border-stone-200 bg-stone-50 px-3 py-2 text-xs tabular-nums text-gray-700" role="gridcell">
        <p><span class="text-gray-500">{{ __('Tid') }}</span> <span class="font-semibold text-ink">{{ $hours($total['minutes']) }}</span></p>
        <p title="{{ $total['incomplete'] ? __('Någon del av kostnaden saknas. Öppna passen med * för att se vad.') : '' }}"><span class="text-gray-500">{{ __('Kostnad') }}</span> <span class="font-semibold text-ink">{{ $money($total['cost']) }}{{ $total['incomplete'] ? ' *' : '' }}</span></p>
        <p>
            <span class="text-gray-500">{{ __('Försäljning') }}</span>
            @if($total['sales'] !== null)
                <span class="font-semibold text-ink">{{ $money($total['sales']) }}</span>
            @elseif($total['reference'] !== null)
                <span class="text-gray-600" title="{{ $need ? $demandText::forecast($need->forecast) : '' }}">≈ {{ $money($total['reference']) }}</span>
            @else
                <span class="text-gray-400">–</span>
            @endif
        </p>
        <p><span class="text-gray-500">{{ __('Personalkostnad') }}</span> <span class="font-semibold text-ink">{{ $total['percent'] === null ? '–' : str_replace('.', ',', (string) $total['percent']).' %' }}</span></p>
        @if($need && $need->needMinutes !== null)
            @php($warning = $demandText::warning($need))
            <p title="{{ $warning ?? __('Bemannat enligt behovet') }}">
                <span class="text-gray-500">{{ __('Behov') }}</span>
                <span @class(['font-semibold', 'text-red-700' => $need->status === 'UNDER', 'text-amber-800' => $need->status === 'OVER', 'text-ink' => ! in_array($need->status, ['UNDER', 'OVER'], true)])>{{ $hours($need->needMinutes) }}</span>
                @if($warning)<span aria-hidden="true">{{ $need->status === 'UNDER' ? '▲' : '▼' }}</span><span class="sr-only">{{ $warning }}</span>@endif
            </p>
        @endif
    </div>
@endforeach
{{-- The week's staff-cost budget (ADR-037, gap 2): the estimated cost against each budget, warnings at the threshold and at 100 %. Never blocks. --}}
@if(($budget ?? null) && ! $budget->isEmpty())
    @php($budgetText = \App\Modules\Planning\Support\Budget\BudgetText::class)
    @php($areaNames = $areas->pluck('name', 'id')->all())
    <div class="border-t border-stone-200 bg-stone-50 px-3 py-2 text-xs text-gray-700" style="grid-column: 1 / -1;" role="gridcell" data-budget>
        <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
            <p class="font-semibold uppercase tracking-wide text-gray-500">{{ __('Budget vecka :week', ['week' => \Carbon\CarbonImmutable::parse($budget->monday)->isoWeek()]) }}</p>
            @foreach($budget->lines() as $line)
                @php($status = $line->status())
                <p class="tabular-nums">
                    <span class="text-gray-500">{{ $budgetText::scope($line, $areaNames) }}</span>
                    <span class="font-semibold text-ink">{{ $money($line->costOre) }}{{ $line->incomplete ? ' *' : '' }}</span>
                    <span class="text-gray-500">{{ __('av') }} {{ $budgetText::budget($line) }}</span>
                    <span @class(['font-semibold', 'text-red-700' => $status === 'OVER', 'text-amber-800' => $status === 'NEAR', 'text-ink' => ! in_array($status, ['OVER', 'NEAR'], true)])>{{ $budgetText::percent($line->usedPercent()) }}</span>
                    @if($status === 'OVER')<span aria-hidden="true">▲</span><span class="sr-only">{{ __('Över budget') }}</span>@elseif($status === 'NEAR')<span aria-hidden="true">!</span><span class="sr-only">{{ __('Nära budget') }}</span>@endif
                </p>
            @endforeach
            <a href="{{ route('planning.budget', ['vecka' => $budget->monday]) }}" class="font-semibold text-accent-700 underline">{{ ($canBudget ?? false) ? __('Ändra budget') : __('Visa budget') }}</a>
        </div>
        @foreach($budget->lines() as $line)
            @if($note = $budgetText::forecastNote($line))
                <p class="mt-1 text-gray-600">{{ $budgetText::scope($line, $areaNames) }}: {{ $note }}</p>
            @endif
        @endforeach
        @foreach($budget->warnings() as $line)
            <p class="mt-1 {{ $line->status() === 'OVER' ? 'text-red-700' : 'text-amber-800' }}">{{ $budgetText::warning($line, $budget->monday, $areaNames) }}</p>
        @endforeach
        <p class="mt-1 text-gray-500">{{ __('Kostnaden är en uppskattning. Budgeten varnar men hindrar aldrig planeringen.') }}</p>
    </div>
@endif
