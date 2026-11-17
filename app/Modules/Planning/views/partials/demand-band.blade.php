{{--
    The demand band in the day view (ADR-037, gap 1): per hour the required staff against the planned,
    at the resolution the source has. With a shape (till or own hour profile) the hours carry sales;
    without one the hours show only the minimum staff and the day's need is per day.
--}}
@php
    use App\Modules\Planning\Domain\Demand\DayDemand;
    use App\Modules\Planning\Support\Demand\DemandText;

    $decimal = fn (float $value): string => str_replace('.', ',', (string) $value);
@endphp
@if($need && ($need->hasRules || $need->forecast->amountOre !== null))
    @php($warning = DemandText::warning($need))
    <div class="grid border-b border-stone-200 bg-stone-50/60" style="grid-template-columns: 12rem 1fr;" role="row">
        <div class="px-3 py-2" role="rowheader">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $need->shape !== null ? __('Behov per timme') : __('Minsta bemanning') }}</p>
            <p class="text-xs tabular-nums text-gray-700">
                {{ __('Prognos') }} <span class="font-semibold text-ink">{{ $need->forecast->amountOre === null ? '–' : $money($need->forecast->amountOre) }}</span>@if($need->forecast->isOverride()) <span class="text-accent-700">{{ __('ändrad') }}</span>@endif
            </p>
        </div>
        <div class="relative h-12 border-l border-stone-200" role="gridcell" aria-label="{{ implode(' ', array_filter([__('Behov'), DemandText::hours($need), $warning])) }}">
            @foreach($need->hours as $hour)
                @continue($hour['need'] === null)
                @php($status = $hour['status'])
                <div
                    @class([
                        'absolute inset-y-1.5 flex items-center justify-center rounded-md text-[11px] font-semibold tabular-nums ring-1',
                        'bg-red-100 text-red-800 ring-red-200' => $status === DayDemand::UNDER,
                        'bg-amber-50 text-amber-900 ring-amber-200' => $status === DayDemand::OVER,
                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $status === DayDemand::OK,
                    ])
                    style="left: calc({{ $hour['hour'] / 24 * 100 }}% + 1px); width: calc(100% / 24 - 2px);"
                    title="{{ sprintf('%02d–%02d', $hour['hour'], ($hour['hour'] + 1) % 24) }}: {{ __('behov :need, bemannat :planned', ['need' => $hour['need'], 'planned' => $decimal($hour['planned'])]) }}@if($hour['open'] > 0), {{ __('öppna pass :open', ['open' => $decimal($hour['open'])]) }}@endif @if($hour['sales'] !== null)· {{ __('prognos :amount', ['amount' => $money($hour['sales'])]) }}@endif"
                    aria-hidden="true"
                >{{ $decimal($hour['planned']) }}/{{ $hour['need'] }}</div>
            @endforeach
        </div>
    </div>
    <div class="border-b border-stone-200 bg-stone-50/60 px-3 py-2 text-xs text-gray-600" role="row">
        <span role="gridcell">
            {{ DemandText::forecast($need->forecast) }}
            {{ DemandText::shape($need->shape) }}.
            @if($line = DemandText::hours($need)){{ $line }}.@endif
            @if($warning)<span @class(['font-semibold', 'text-red-700' => $need->status === DayDemand::UNDER, 'text-amber-800' => $need->status === DayDemand::OVER])>{{ $warning }}</span>@endif
            @unless($need->hasRules)<a href="{{ route('planning.demand') }}" class="font-semibold text-accent-700 underline">{{ __('Lägg till bemanningsmål') }}</a>@endunless
        </span>
    </div>
@endif
