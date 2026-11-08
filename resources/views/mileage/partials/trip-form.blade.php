{{-- The trip form (ADR-072), shared by Min sida and Bokföring → Körjournal. Typed by the person; no position is read. --}}
@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $selected = $vehicles->firstWhere('id', (int) ($trip['vehicle_id'] ?? 0));
    $private = ($trip['kind'] ?? '') === 'PRIVATE';
@endphp
<form wire:submit="saveTrip" class="mt-3 space-y-4" aria-label="{{ __('Resa') }}">
    @if($routes->isNotEmpty() && ! $private)
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-gray-600">{{ __('Sparade vägar:') }}</span>
            @foreach($routes as $route)
                <button type="button" class="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-800 hover:border-gray-300" wire:click="useRoute({{ $route->id }})" wire:key="route-{{ $route->id }}">{{ $route->label }} · {{ App\Domain\Mileage\Distance::formatKm($route->distance_hm) }}</button>
            @endforeach
        </div>
    @endif
    <div class="grid gap-4 sm:grid-cols-2">
        <x-ui.field for="trip_vehicle" :label="__('Bil')" error="vehicle_id">
            <select id="trip_vehicle" class="{{ $input }} mt-1" wire:model.live="trip.vehicle_id">
                <option value="">{{ __('Välj bil…') }}</option>
                @foreach($vehicles as $vehicle)
                    <option value="{{ $vehicle->id }}">{{ $vehicle->displayName() }} · {{ $vehicle->kind->label() }}</option>
                @endforeach
            </select>
            @if($allowOwnCar ?? false)
                <button type="button" class="mt-1 text-sm text-accent-700 underline" wire:click="$set('carOpen', true)">{{ __('Lägg till din egen bil') }}</button>
            @endif
        </x-ui.field>
        <x-ui.field for="trip_kind" :label="__('Typ av resa')" error="kind">
            <select id="trip_kind" class="{{ $input }} mt-1" wire:model.live="trip.kind">
                @foreach($kinds as $kind)
                    <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <x-ui.field for="trip_date" :label="__('Datum')" error="trip_date">
            <input id="trip_date" type="date" class="{{ $input }} mt-1" wire:model="trip.trip_date">
        </x-ui.field>
        <x-ui.field for="trip_distance" :label="__('Sträcka, km')" error="distance" :hint="__('Eller fyll i mätarställningen nedan, så räknas sträckan ut.')">
            <input id="trip_distance" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="trip.distance" placeholder="{{ __('Till exempel 42 eller 118,5') }}">
        </x-ui.field>
        @unless($private)
            <x-ui.field for="trip_odometer_start" :label="__('Mätarställning vid start, km')" error="odometer_start">
                <input id="trip_odometer_start" type="number" min="0" class="{{ $input }} mt-1" wire:model="trip.odometer_start">
            </x-ui.field>
            <x-ui.field for="trip_odometer_end" :label="__('Mätarställning vid slut, km')" error="odometer_end">
                <input id="trip_odometer_end" type="number" min="0" class="{{ $input }} mt-1" wire:model="trip.odometer_end">
            </x-ui.field>
            <x-ui.field for="trip_from" :label="__('Från')" error="from_place">
                <input id="trip_from" type="text" class="{{ $input }} mt-1" wire:model="trip.from_place">
            </x-ui.field>
            <x-ui.field for="trip_to" :label="__('Till')" error="to_place">
                <input id="trip_to" type="text" class="{{ $input }} mt-1" wire:model="trip.to_place">
            </x-ui.field>
            <x-ui.field for="trip_purpose" :label="__('Ärende')" error="purpose" class="sm:col-span-2" :hint="__('Vilken kund eller vilket ärende resan gällde. Krävs för en tjänsteresa.')">
                <input id="trip_purpose" type="text" class="{{ $input }} mt-1" wire:model="trip.purpose">
            </x-ui.field>
            @if($projects->isNotEmpty())
                <x-ui.field for="trip_project" :label="__('Projekt (valfritt)')" error="project_id">
                    <select id="trip_project" class="{{ $input }} mt-1" wire:model="trip.project_id">
                        <option value="">{{ __('Inget projekt') }}</option>
                        @foreach($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->code }} {{ $project->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endif
        @else
            <p class="text-sm text-gray-600 sm:col-span-2">{{ __('För en privat resa sparas bara datum och sträcka.') }}</p>
        @endunless
    </div>
    <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
        @if($selected?->isCompanyCar() && $selected->fuel === 'PLUG_IN_HYBRID' && ! $private)
            <label class="flex items-center gap-2"><input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="trip.charged_at_workplace"> {{ __('Laddad på arbetsplatsen') }}</label>
        @endif
        @if($editingTrip === null && ! $private)
            <label class="flex items-center gap-2"><input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="trip.round_trip"> {{ __('Tur och retur (sparas som två resor)') }}</label>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-4">
        <x-button wire:loading.attr="disabled" wire:target="saveTrip">{{ $editingTrip === null ? __('Spara resan') : __('Spara ändringen') }}</x-button>
        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('tripOpen', false)">{{ __('Avbryt') }}</button>
    </div>
</form>

@if($carOpen)
    <form wire:submit="saveOwnCar" class="mt-4 grid gap-4 rounded-lg bg-gray-50 p-4 sm:grid-cols-2" aria-label="{{ __('Din egen bil') }}">
        <x-ui.field for="car_label" :label="__('Bilens namn')" error="label">
            <input id="car_label" type="text" class="{{ $input }} mt-1" wire:model="car.label" placeholder="{{ __('Till exempel Volvo V70') }}">
        </x-ui.field>
        <x-ui.field for="car_registration" :label="__('Registreringsnummer (valfritt)')" error="registration_number">
            <input id="car_registration" type="text" class="{{ $input }} mt-1" wire:model="car.registration_number">
        </x-ui.field>
        <div class="flex items-center gap-4 sm:col-span-2">
            <x-button>{{ __('Lägg till bilen') }}</x-button>
            <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('carOpen', false)">{{ __('Avbryt') }}</button>
        </div>
    </form>
@endif

<details class="mt-4 text-sm">
    <summary class="cursor-pointer text-gray-700">{{ __('Spara en väg du kör ofta') }}</summary>
    <form wire:submit="saveRoute" class="mt-3 flex flex-wrap items-end gap-3">
        <x-ui.field for="route_label" :label="__('Väg')" error="route_label" class="min-w-48 flex-1">
            <input id="route_label" type="text" class="{{ $input }} mt-1" wire:model="routeLabel" placeholder="{{ __('Kontoret – Lagret') }}">
        </x-ui.field>
        <x-ui.field for="route_distance" :label="__('Sträcka, km')" error="route_distance">
            <input id="route_distance" type="text" inputmode="decimal" class="{{ $input }} mt-1 w-28" wire:model="routeDistance">
        </x-ui.field>
        <x-button>{{ __('Spara vägen') }}</x-button>
    </form>
    @foreach($routes as $route)
        <p class="mt-2 flex items-center gap-3" wire:key="saved-route-{{ $route->id }}">{{ $route->label }} · {{ App\Domain\Mileage\Distance::formatKm($route->distance_hm) }}
            <button type="button" class="text-gray-600 underline" wire:click="deleteRoute({{ $route->id }})" wire:confirm="{{ __('Ta bort den sparade vägen?') }}">{{ __('Ta bort') }}</button>
        </p>
    @endforeach
</details>
