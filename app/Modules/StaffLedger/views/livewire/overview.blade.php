<div wire:poll.15s class="space-y-8">
    @if($locations->isEmpty())
        <x-ui.empty-state :title="__('Lägg till din första lokal')">
            {{ __('Börja med lokalen där personalen arbetar. Sedan lägger du till personalen och kopplar en surfplatta som kiosk vid entrén.') }}
            @if($canManage)
                <x-slot:cta>
                    <a href="{{ route('staff-ledger.locations') }}" class="inline-flex min-h-11 items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Lägg till lokal') }}</a>
                </x-slot:cta>
            @endif
        </x-ui.empty-state>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach($locations as $location)
            @php($here = $presentByLocation->get($location->id, collect()))
            <x-ui.card :title="$location->name" :description="$location->address().' · '.$location->business_type->label()">
                <x-slot:action>
                    <x-ui.badge tone="{{ $here->isEmpty() ? 'neutral' : 'success' }}">{{ trans_choice('{0} Ingen på plats|{1} 1 på plats|[2,*] :count på plats', $here->count(), ['count' => $here->count()]) }}</x-ui.badge>
                </x-slot:action>
                @if($here->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('Ingen är incheckad just nu.') }}</p>
                @else
                    <ul class="divide-y divide-stone-200">
                        @foreach($here as $shift)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span class="font-medium text-ink">{{ $shift->personName }}@if($shift->employerName) <span class="text-sm font-normal text-gray-600">({{ $shift->employerName }})</span>@endif</span>
                                <span class="text-sm tabular-nums text-gray-700">{{ __('sedan :time', ['time' => $shift->start?->format($shift->start?->isToday() ? 'H:i' : 'Y-m-d H:i')]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($location->kiosk_enabled && $location->active_devices_count === 0)
                    <p class="mt-4 text-sm text-gray-600">{{ __('Ingen kiosk är kopplad till lokalen.') }} <a href="{{ route('staff-ledger.locations') }}" class="font-semibold text-accent-700 underline">{{ __('Koppla en surfplatta') }}</a></p>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.card :title="__('Avvikelser')" :description="__('Det här bör en chef se över. Rätta i liggaren; inget ändras automatiskt.')">
        <div class="space-y-6">
            <section>
                <h3 class="font-semibold text-ink">{{ __('Glömda utcheckningar (incheckad mer än :hours timmar)', ['hours' => $reminderHours]) }}</h3>
                @forelse($forgotten as $shift)
                    <p class="mt-1 text-sm text-gray-700">{{ __(':name, incheckad :time', ['name' => $shift->personName, 'time' => $shift->start?->format('Y-m-d H:i')]) }}</p>
                @empty
                    <p class="mt-1 text-sm text-gray-600">{{ __('Inga.') }}</p>
                @endforelse
            </section>
            <section>
                <h3 class="font-semibold text-ink">{{ __('Långa arbetspass (:hours timmar eller mer, senaste veckan)', ['hours' => $longHours]) }}</h3>
                @forelse($long as $shift)
                    <p class="mt-1 text-sm text-gray-700">{{ __(':name, :from–:to (:hours h)', ['name' => $shift->personName, 'from' => $shift->start?->format('Y-m-d H:i'), 'to' => $shift->end?->format('H:i'), 'hours' => number_format((float) $shift->hours(), 1, ',', '')]) }}</p>
                @empty
                    <p class="mt-1 text-sm text-gray-600">{{ __('Inga.') }}</p>
                @endforelse
            </section>
            <section>
                <h3 class="font-semibold text-ink">{{ __('Utcheckning utan incheckning (senaste veckan)') }}</h3>
                @forelse($missingStart as $shift)
                    <p class="mt-1 text-sm text-gray-700">{{ __(':name, utcheckad :time', ['name' => $shift->personName, 'time' => $shift->end?->format('Y-m-d H:i')]) }}</p>
                @empty
                    <p class="mt-1 text-sm text-gray-600">{{ __('Inga.') }}</p>
                @endforelse
            </section>
            <p class="text-sm text-gray-500">{{ __('Incheckad utan schema visas när NONOX har scheman för personalen.') }}</p>
        </div>
    </x-ui.card>

    <p class="text-sm text-gray-600">
        @if($verification === null)
            {{ __('Liggarens hashkedja kontrolleras varje natt.') }}
        @elseif($verification->payload['ok'] ?? false)
            {{ __('Hashkedjan kontrollerades :time: inga avvikelser.', ['time' => $verification->created_at->format('Y-m-d H:i')]) }}
        @else
            <span class="font-semibold text-red-800">{{ __('Hashkedjan kontrollerades :time och avvikelser hittades. Kontakta den som driftar NONOX.', ['time' => $verification->created_at->format('Y-m-d H:i')]) }}</span>
        @endif
    </p>
</div>
