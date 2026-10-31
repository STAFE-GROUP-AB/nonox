<div lang="{{ $language }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" class="mx-auto max-w-xl space-y-6">
    <h1 class="text-3xl font-bold tracking-tight text-ink">{{ $strings['mine_title'] }}</h1>

    @if($person === null)
        <x-ui.empty-state :title="$strings['mine_not_linked']" />
    @else
        @foreach($locations as $location)
            @php($open = $present->get($location->id))
            <x-ui.card :title="$location->name" :description="$location->address()">
                <p class="text-base text-gray-700">{{ $open ? str_replace(':time', $open->start?->format('H:i') ?? '', $strings['mine_status_in']) : $strings['mine_status_out'] }}</p>
                <div x-data="{ busy: false }" class="mt-4">
                    <button type="button"
                        class="flex min-h-16 w-full items-center justify-center rounded-2xl bg-accent-600 px-6 text-xl font-bold text-white hover:bg-accent-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-accent-500 disabled:opacity-60"
                        x-bind:disabled="busy"
                        x-on:click="
                            busy = true;
                            navigator.geolocation.getCurrentPosition(
                                (position) => $wire.checkIn({{ $location->id }}, position.coords.latitude, position.coords.longitude, position.coords.accuracy).finally(() => busy = false),
                                () => { busy = false; $wire.locationDenied(); },
                                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
                            )">
                        <span x-show="! busy">{{ $open ? $strings['mine_check_out'] : $strings['mine_check_in'] }}</span>
                        <span x-show="busy" x-cloak>{{ $strings['mine_locating'] }}</span>
                    </button>
                </div>
            </x-ui.card>
        @endforeach

        <x-ui.card :title="$strings['mine_qr']" :description="$strings['mine_qr_hint']">
            <img src="{{ route('staff-ledger.mine.qr') }}" alt="{{ $strings['mine_qr'] }}" class="mx-auto size-64 rounded-xl bg-white p-2">
        </x-ui.card>

        @if($locations->isEmpty())
            <p class="text-sm text-gray-600">{{ $strings['mine_no_mobile'] }}</p>
        @endif
    @endif
</div>
