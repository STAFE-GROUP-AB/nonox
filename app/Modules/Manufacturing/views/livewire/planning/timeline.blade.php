@php($date = fn ($day) => $day?->locale('sv')->isoFormat('D MMM'))
<div class="space-y-4">
    @include('manufacturing::livewire.planning.run-bar')

    <div class="flex flex-wrap items-center gap-2">
        <x-secondary-button type="button" wire:click="shift(-7)">{{ __('Föregående vecka') }}</x-secondary-button>
        <x-secondary-button type="button" wire:click="today">{{ __('I dag') }}</x-secondary-button>
        <x-secondary-button type="button" wire:click="shift(7)">{{ __('Nästa vecka') }}</x-secondary-button>
        <span class="text-sm text-gray-600">{{ __(':from – :to', ['from' => $date($start), 'to' => $date($end->subDay())]) }}</span>
    </div>

    <x-ui.card>
        @if($forecasts->isEmpty())
            <x-ui.empty-state :title="__('Inget att planera ännu')">
                {{ __('Planen visar frisläppta och startade tillverkningsordrar, och utkast som hör till en öppen kundorder. Lägg upp personer och arbetsstationer under Inställningar, och se till att arbetsstegen har en kompetens.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <div class="min-w-[56rem]">
                    <div class="grid grid-cols-[14rem_1fr] border-b border-stone-200 pb-2 text-xs text-gray-500">
                        <div>{{ __('Order') }}</div>
                        <div class="grid" style="grid-template-columns: repeat({{ count($days) }}, minmax(0, 1fr))">
                            @foreach($days as $day)
                                <div @class(['text-center', 'text-gray-400' => $day->isWeekend()])>{{ $day->locale('sv')->isoFormat('dd D/M') }}</div>
                            @endforeach
                        </div>
                    </div>
                    @foreach($forecasts as $forecast)
                        @php($order = $forecast->order)
                        <div wire:key="row-{{ $forecast->id }}" class="grid grid-cols-[14rem_1fr] items-center border-b border-stone-100 py-2">
                            <div class="min-w-0 pr-3 text-sm">
                                <p class="font-semibold text-ink">{{ $order->reference() }} <span class="font-normal text-gray-600">{{ $order->name }}</span></p>
                                <p class="text-xs text-gray-600">
                                    {{ $forecast->salesDocument?->customer?->name ?? __('Lagertillverkning') }}
                                    · <x-ui.badge :tone="$forecast->status->tone()">{{ $forecast->status->label() }}</x-ui.badge>
                                </p>
                            </div>
                            <div class="relative h-9 rounded bg-stone-50" role="img" aria-label="{{ __(':order: prognos :forecast, utlovat :promised', ['order' => $order->reference(), 'forecast' => $forecast->forecast_delivery_on?->toDateString() ?? __('efter horisonten'), 'promised' => $forecast->promised_on?->toDateString() ?? '–']) }}">
                                @foreach($bars->get($order->id, collect()) as $bar)
                                    @php($left = $position($bar->starts_at->setTimezone('Europe/Stockholm')))
                                    @php($right = $position($bar->ends_at->setTimezone('Europe/Stockholm')))
                                    <div class="absolute top-1 bottom-1 overflow-hidden rounded {{ $bar->locked ? 'bg-ink text-white' : 'bg-accent-500 text-white' }} px-1 text-[11px] leading-7 whitespace-nowrap"
                                        style="left: {{ $left }}%; width: {{ max(0.6, $right - $left) }}%"
                                        title="{{ $bar->orderOperation->operation->name }}: {{ trim(($bar->employee?->first_name ?? '').' '.($bar->employee?->last_name ?? '')) }}{{ $bar->workstation ? ', '.$bar->workstation->name : '' }}, {{ $bar->starts_at->setTimezone('Europe/Stockholm')->format('j/n H:i') }}–{{ $bar->ends_at->setTimezone('Europe/Stockholm')->format('j/n H:i') }}">
                                        {{ $bar->orderOperation->operation->name }}
                                    </div>
                                @endforeach
                                @if($forecast->promised_on && $forecast->promised_on->greaterThanOrEqualTo($start) && $forecast->promised_on->lessThan($end))
                                    <div class="absolute top-0 bottom-0 w-0.5 bg-red-600" style="left: {{ $position($forecast->promised_on->setTimezone('Europe/Stockholm')->endOfDay()) }}%" title="{{ __('Utlovat :date', ['date' => $forecast->promised_on->toDateString()]) }}"></div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <dl class="mt-4 grid gap-1 text-xs text-gray-600 sm:grid-cols-3">
                <div><span class="inline-block size-3 rounded bg-accent-500 align-middle"></span> {{ __('Planerat arbetssteg') }}</div>
                <div><span class="inline-block size-3 rounded bg-ink align-middle"></span> {{ __('Låst av planerare') }}</div>
                <div><span class="inline-block h-3 w-0.5 bg-red-600 align-middle"></span> {{ __('Utlovat datum') }}</div>
            </dl>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Prognos per order')">
        <ul class="divide-y divide-stone-100 text-sm">
            @forelse($forecasts as $forecast)
                <li wire:key="forecast-{{ $forecast->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>{{ $forecast->order->reference() }} {{ $forecast->order->name }}</span>
                    <span class="tabular-nums">
                        {{ __('Prognos') }} {{ $forecast->forecast_delivery_on?->toDateString() ?? __('efter horisonten') }}
                        · {{ __('utlovat') }} {{ $forecast->promised_on?->toDateString() ?? '–' }}
                        <x-ui.badge :tone="$forecast->status->tone()">{{ $forecast->status->label() }}</x-ui.badge>
                    </span>
                </li>
            @empty
                <li class="py-2 text-gray-600">{{ __('Inga ordrar i planen.') }}</li>
            @endforelse
        </ul>
        <p class="mt-4 text-xs text-gray-500">{{ __('Planen är beräknad, inte bekräftad. Den lägger arbetet i tur och ordning efter prioritet och utlovat datum och söker ingen bästa lösning; ställtider mellan ordrar räknas inte, och ingenting beräknas efter horisonten.') }}</p>
    </x-ui.card>
</div>
