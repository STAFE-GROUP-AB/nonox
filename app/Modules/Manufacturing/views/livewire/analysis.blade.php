@php($duration = fn (int $minutes) => App\Modules\Manufacturing\Livewire\OperationsPage::duration($minutes))
@php($signed = fn (int $value) => ($value > 0 ? '+' : ($value < 0 ? '−' : '')).abs($value).' %')
<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <label for="period" class="text-sm font-medium text-gray-700">{{ __('Färdiga order senaste') }}</label>
        <select id="period" wire:model.live="months" class="rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
            @foreach($periods as $period)
                <option value="{{ $period }}">{{ trans_choice(':count månad|:count månader', $period, ['count' => $period]) }}</option>
            @endforeach
        </select>
        <span class="text-sm text-gray-500">{{ __('från :date', ['date' => $analysis['since']->format('Y-m-d')]) }}</span>
    </div>

    <x-ui.card :title="__('Kalkyl mot utfall per produkt och arbetssteg')" :description="__('Utfallet är den tid som rapporterats på surfplattan eller angetts när ordern färdigmeldades. Tid som bara fyllts i från planen räknas inte som utfall. Avvikelsen är utfall minus kalkyl, i procent av kalkylen.')">
        @if(count($analysis['products']) === 0)
            <x-ui.empty-state :title="__('Inga färdiga order med rapporterad tid')">{{ __('När order färdigmeldats med rapporterad tid visas jämförelsen här.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pe-4 font-medium">{{ __('Produkt') }}</th>
                            <th class="py-2 pe-4 font-medium">{{ __('Arbetssteg') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Order') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Kalkyl') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Utfall') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Avvikelse') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Spridning (min, median, max)') }}</th>
                            <th class="py-2 font-medium">{{ __('Utveckling') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($analysis['products'] as $row)
                            <tr>
                                <td class="py-2 pe-4 font-semibold text-ink">{{ $row['article'] }}</td>
                                <td class="py-2 pe-4">{{ $row['operation'] }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $row['orders'] }}@if($row['unreported'])<span class="text-gray-500" title="{{ __('Order utan rapporterad tid i det här steget') }}"> (+{{ $row['unreported'] }})</span>@endif</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $duration($row['planned_minutes']) }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $duration($row['actual_minutes']) }}</td>
                                <td class="py-2 pe-4 text-right font-semibold tabular-nums {{ $row['deviation_percent'] > 0 ? 'text-red-700' : 'text-green-700' }}">{{ $signed($row['deviation_percent']) }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">@if($row['detail_hidden']) <span class="text-gray-500" title="{{ __('Visas inte när färre än tre personer har rapporterat, så att det inte går att läsa ut en enskild persons arbete.') }}">{{ __('Dolt') }}</span> @else {{ $signed($row['spread']['min']) }}, {{ $signed($row['spread']['median']) }}, {{ $signed($row['spread']['max']) }} @endif</td>
                                <td class="py-2">
                                    @if($row['detail_hidden'])
                                        <span class="text-gray-500">{{ __('Dolt') }}</span>
                                    @elseif($row['trend'] === null)
                                        <span class="text-gray-500">{{ __('För få order') }}</span>
                                    @else
                                        <span class="font-semibold {{ $row['trend']['direction'] === 'worse' ? 'text-red-700' : ($row['trend']['direction'] === 'better' ? 'text-green-700' : 'text-gray-700') }}">
                                            {{ match($row['trend']['direction']) { 'better' => __('Förbättras'), 'worse' => __('Försämras'), default => __('Stabil') } }}
                                            ({{ $signed($row['trend']['change']) }})
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">{{ __('Utvecklingen jämför den nyare halvan av ordrarna med den äldre, i procentenheter, och visas först från fyra order. Siffran inom parentes i kolumnen Order är order utan rapporterad tid.') }}</p>
        @endif
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card :title="__('Köer mot kapacitet')" :description="__('Återstående planerad tid på öppna order, per arbetssteg. Dagar är återstående tid delad med stegets kapacitet per dag.')">
            @if(count($analysis['queue']) === 0)
                <x-ui.empty-state :title="__('Ingen kö just nu')">{{ __('Det finns ingen återstående planerad tid på öppna order.') }}</x-ui.empty-state>
            @else
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pe-4 font-medium">{{ __('Arbetssteg') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Återstår') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Order') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Dagar i kö') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($analysis['queue'] as $row)
                            <tr>
                                <td class="py-2 pe-4 font-semibold text-ink">{{ $row['operation'] }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $duration($row['remaining_minutes']) }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $row['orders'] }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $row['days'] === null ? __('Ingen kapacitet angiven') : str_replace('.', ',', (string) $row['days']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Största överskridanden')" :description="__('Arbetsstegen där utfallet mest överstigit kalkylen på färdiga order, i minuter.')">
            @if(count($analysis['overruns']) === 0)
                <x-ui.empty-state :title="__('Inga överskridanden')">{{ __('Inget arbetssteg har överstigit kalkylen under perioden.') }}</x-ui.empty-state>
                @if($analysis['overruns_hidden'] > 0)
                    <p class="mt-3 text-xs text-gray-500">{{ trans_choice(':count arbetssteg är dolt eftersom färre än tre personer har rapporterat.|:count arbetssteg är dolda eftersom färre än tre personer har rapporterat.', $analysis['overruns_hidden'], ['count' => $analysis['overruns_hidden']]) }}</p>
                @endif
            @else
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pe-4 font-medium">{{ __('Arbetssteg') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Över kalkyl') }}</th>
                            <th class="py-2 pe-4 text-right font-medium">{{ __('Order över') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Avvikelse totalt') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($analysis['overruns'] as $row)
                            <tr>
                                <td class="py-2 pe-4 font-semibold text-ink">{{ $row['operation'] }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $duration($row['over']) }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $row['over_orders'] }} / {{ $row['orders'] }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $signed($row['deviation_percent']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($analysis['overruns_hidden'] > 0)
                    <p class="mt-3 text-xs text-gray-500">{{ trans_choice(':count arbetssteg är dolt eftersom färre än tre personer har rapporterat.|:count arbetssteg är dolda eftersom färre än tre personer har rapporterat.', $analysis['overruns_hidden'], ['count' => $analysis['overruns_hidden']]) }}</p>
                @endif
            @endif
        </x-ui.card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach(['material' => __('Väntar på material'), 'work' => __('Väntar på arbete')] as $kind => $title)
            <x-ui.card :title="$title" :description="$kind === 'material' ? __('Öppna order där ett arbetssteg markerats som väntande på material. Rättas av inköp och leveranser.') : __('Öppna order där materialet finns men arbetssteget väntar på arbete eller kapacitet. Rättas av planering och bemanning.')">
                @if(count($analysis['waiting'][$kind]) === 0)
                    <x-ui.empty-state :title="__('Inga order väntar')">{{ __('Inget arbetssteg är markerat just nu.') }}</x-ui.empty-state>
                @else
                    <ul class="divide-y divide-stone-100">
                        @foreach($analysis['waiting'][$kind] as $row)
                            <li class="py-2 text-sm">
                                <span class="font-semibold text-ink">{{ $row['reference'] }} {{ $row['product'] }}</span>
                                <span class="text-gray-600"> · {{ $row['operation'] }} · {{ __('sedan :time', ['time' => $duration($row['minutes'])]) }}</span>
                                <p class="text-gray-700">{{ $row['reason'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-3 text-xs text-gray-500">{{ __('Under perioden: :count markeringar, :time väntetid.', ['count' => $analysis['waited'][$kind]['flags'], 'time' => $duration($analysis['waited'][$kind]['minutes'])]) }}</p>
            </x-ui.card>
        @endforeach
    </div>
</div>
