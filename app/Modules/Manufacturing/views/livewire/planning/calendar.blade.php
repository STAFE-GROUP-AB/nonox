<div class="space-y-4">
    @include('manufacturing::livewire.planning.run-bar')

    <div class="flex flex-wrap items-center gap-2">
        <x-secondary-button type="button" wire:click="shift(-1)">{{ __('Föregående') }}</x-secondary-button>
        <x-secondary-button type="button" wire:click="shift(1)">{{ __('Nästa') }}</x-secondary-button>
        <span class="text-sm font-semibold text-ink">{{ $view === 'vecka' ? __('Vecka :w', ['w' => $base->isoWeek()]) : ucfirst($base->locale('sv')->isoFormat('MMMM YYYY')) }}</span>
        <div class="ml-auto flex gap-2" role="group" aria-label="{{ __('Visa') }}">
            <button type="button" wire:click="show('manad')" @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-accent-50 text-accent-700' => $view === 'manad', 'text-gray-600' => $view !== 'manad']) aria-pressed="{{ $view === 'manad' ? 'true' : 'false' }}">{{ __('Månad') }}</button>
            <button type="button" wire:click="show('vecka')" @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-accent-50 text-accent-700' => $view === 'vecka', 'text-gray-600' => $view !== 'vecka']) aria-pressed="{{ $view === 'vecka' ? 'true' : 'false' }}">{{ __('Vecka') }}</button>
        </div>
    </div>

    <x-ui.card>
        <div class="overflow-x-auto">
            <div class="grid min-w-[48rem] grid-cols-7 gap-px rounded-lg bg-stone-200 text-xs">
                @foreach(['må', 'ti', 'on', 'to', 'fr', 'lö', 'sö'] as $weekday)
                    <div class="bg-stone-50 px-2 py-1 font-medium text-gray-500">{{ $weekday }}</div>
                @endforeach
                @foreach($days as $day)
                    @php($key = $day->toDateString())
                    <div wire:key="day-{{ $key }}" @class(['min-h-24 bg-white p-2', 'opacity-60' => $view === 'manad' && $day->month !== $base->month])>
                        <p @class(['font-semibold', 'text-accent-700' => $day->isToday(), 'text-gray-700' => ! $day->isToday()])>{{ $day->day }}</p>
                        <ul class="mt-1 space-y-1">
                            @foreach($deliveries->get($key, collect()) as $forecast)
                                <li><x-ui.badge :tone="$forecast->status->tone()">{{ __('Leverans') }} {{ $forecast->order->reference() }}</x-ui.badge></li>
                            @endforeach
                            @foreach($finishes->get($key, collect()) as $forecast)
                                <li class="text-gray-600">{{ __('Klar') }} {{ $forecast->order->reference() }} {{ $forecast->finish_at->setTimezone('Europe/Stockholm')->format('H:i') }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
        @if($beyond->isNotEmpty())
            <p class="mt-4 text-sm text-red-700">{{ __('Efter horisonten: :orders', ['orders' => $beyond->map(fn ($forecast) => $forecast->order->reference())->join(', ')]) }}</p>
        @endif
        <p class="mt-2 text-xs text-gray-500">{{ __('Datumen är beräknade, inte bekräftade. Ett leveransdatum blir bekräftat först när ni bekräftar det till kunden.') }}</p>
    </x-ui.card>
</div>
