{{-- The footer of the grid: per day planned hours, estimated cost, sales from the books and labour cost as a share of sales. --}}
<div class="sticky left-0 z-10 bg-stone-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500" role="rowheader">{{ __('Summa') }}</div>
@foreach($days as $day)
    @php($total = $totals[$day->toDateString()])
    <div class="border-l border-stone-200 bg-stone-50 px-3 py-2 text-xs tabular-nums text-gray-700" role="gridcell">
        <p><span class="text-gray-500">{{ __('Tid') }}</span> <span class="font-semibold text-ink">{{ $hours($total['minutes']) }}</span></p>
        <p title="{{ $total['incomplete'] ? __('Någon del av kostnaden saknas. Öppna passen med * för att se vad.') : '' }}"><span class="text-gray-500">{{ __('Kostnad') }}</span> <span class="font-semibold text-ink">{{ $money($total['cost']) }}{{ $total['incomplete'] ? ' *' : '' }}</span></p>
        <p>
            <span class="text-gray-500">{{ __('Försäljning') }}</span>
            @if($total['sales'] !== null)
                <span class="font-semibold text-ink">{{ $money($total['sales']) }}</span>
            @elseif($total['reference'] !== null)
                <span class="text-gray-600" title="{{ __('Snitt för samma veckodag de fyra veckorna före') }}">≈ {{ $money($total['reference']) }}</span>
            @else
                <span class="text-gray-400">–</span>
            @endif
        </p>
        <p><span class="text-gray-500">{{ __('Personalkostnad') }}</span> <span class="font-semibold text-ink">{{ $total['percent'] === null ? '–' : str_replace('.', ',', (string) $total['percent']).' %' }}</span></p>
    </div>
@endforeach
