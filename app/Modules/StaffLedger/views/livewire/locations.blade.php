@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')
<div class="space-y-8">
    @if($pairing)
        <x-ui.card :title="__('Koppla kiosken :name', ['name' => $pairing['device']])" :description="__('Öppna länken i surfplattans webbläsare, eller skanna QR-koden med den. Koden gäller till :time och kan bara användas en gång.', ['time' => $pairing['expires']])">
            <div class="flex flex-wrap items-center gap-6">
                <div class="size-48 shrink-0 [&_svg]:size-full" aria-hidden="true">{!! $pairingQr !!}</div>
                <div class="space-y-2">
                    <p class="text-sm text-gray-600">{{ __('Kopplingskod') }}</p>
                    <p class="text-3xl font-bold tracking-widest tabular-nums text-ink">{{ $pairing['code'] }}</p>
                    <p class="break-all text-sm"><a href="{{ $pairing['url'] }}" target="_blank" rel="noopener" class="font-semibold text-accent-700 underline">{{ $pairing['url'] }}</a></p>
                    <p class="text-sm text-gray-600">{{ __('Lägg sedan till sidan på hemskärmen, så öppnas kiosken i helskärm och fungerar utan internet.') }}</p>
                </div>
            </div>
        </x-ui.card>
    @endif

    @if($canManage && $editing === null)
        <div><x-button type="button" wire:click="create">{{ __('Lägg till lokal') }}</x-button></div>
    @endif

    @if($editing !== null)
        <x-ui.card :title="$editing === 0 ? __('Ny lokal') : __('Ändra lokal')" :description="__('Flera företag i samma lokal för var sin liggare. En kiosk hör till ett företag och en lokal.')">
            <form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
                <x-ui.field for="loc-name" :label="__('Namn')" error="form.name" class="sm:col-span-2">
                    <input id="loc-name" wire:model="form.name" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="loc-address" :label="__('Adress')" error="form.address_line1" class="sm:col-span-2">
                    <input id="loc-address" wire:model="form.address_line1" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="loc-postal" :label="__('Postnummer')" error="form.postal_code">
                    <input id="loc-postal" wire:model="form.postal_code" class="{{ $input }}" inputmode="numeric" required>
                </x-ui.field>
                <x-ui.field for="loc-city" :label="__('Ort')" error="form.city">
                    <input id="loc-city" wire:model="form.city" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="loc-type" :label="__('Verksamhet')" error="form.business_type" class="sm:col-span-2" :hint="__('Har lokalen inget krav på personalliggare kan du välja Övrig verksamhet och använda in- och utcheckningen som tidsredovisning.')">
                    <select id="loc-type" wire:model="form.business_type" class="{{ $input }}">
                        @foreach($types as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <fieldset class="sm:col-span-2">
                    <legend class="text-sm font-medium text-gray-700">{{ __('Sätt att checka in') }}</legend>
                    <div class="mt-2 space-y-2">
                        <label class="flex items-center gap-3"><x-checkbox wire:model="form.kiosk_enabled" /> <span>{{ __('Kiosk med namn och kod') }}</span></label>
                        <label class="flex items-center gap-3"><x-checkbox wire:model="form.qr_enabled" /> <span>{{ __('Personlig QR-kod som kioskens kamera läser') }}</span></label>
                        <label class="flex items-center gap-3"><x-checkbox wire:model="form.mobile_enabled" /> <span>{{ __('Mobil med platskontroll (kräver koordinater)') }}</span></label>
                    </div>
                    <x-input-error for="form.kiosk_enabled" class="mt-2" />
                </fieldset>
                <x-ui.field for="loc-lat" :label="__('Latitud')" :hint="__('Till exempel 59.3293. Behövs bara för mobil incheckning.')" error="form.latitude">
                    <input id="loc-lat" wire:model="form.latitude" class="{{ $input }}" inputmode="decimal">
                </x-ui.field>
                <x-ui.field for="loc-lng" :label="__('Longitud')" error="form.longitude">
                    <input id="loc-lng" wire:model="form.longitude" class="{{ $input }}" inputmode="decimal">
                </x-ui.field>
                <x-ui.field for="loc-radius" :label="__('Radie i meter')" :hint="__('Hur långt från lokalen en mobil incheckning godtas.')" error="form.radius_meters">
                    <input id="loc-radius" type="number" min="25" max="2000" wire:model="form.radius_meters" class="{{ $input }}">
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-2">
                    <x-button type="submit">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editing', null)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @forelse($locations as $location)
        <x-ui.card :title="$location->name.($location->archived_at ? ' ('.__('arkiverad').')' : '')" :description="$location->address().' · '.$location->business_type->label()">
            @if($canManage)
                <x-slot:action>
                    <div class="flex gap-3 text-sm font-semibold">
                        <button type="button" wire:click="edit({{ $location->id }})" class="text-accent-700 underline">{{ __('Ändra') }}</button>
                        <button type="button" wire:click="archive({{ $location->id }})" wire:confirm="{{ $location->archived_at ? __('Göra lokalen aktiv igen?') : __('Arkivera lokalen? Kiosker slutar visa den, men liggaren finns kvar.') }}" class="text-gray-700 underline">{{ $location->archived_at ? __('Återställ') : __('Arkivera') }}</button>
                    </div>
                </x-slot:action>
            @endif
            <p class="text-sm text-gray-700">
                {{ collect([$location->kiosk_enabled ? __('Kiosk') : null, $location->qr_enabled ? __('QR-kod') : null, $location->mobile_enabled ? __('Mobil inom :meters m', ['meters' => $location->radius_meters]) : null])->filter()->join(' · ') }}
            </p>
            <h3 class="mt-5 font-semibold text-ink">{{ __('Kiosker') }}</h3>
            <ul class="mt-2 divide-y divide-stone-200">
                @forelse($location->devices as $device)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                        <span class="font-medium text-ink">{{ $device->name }}</span>
                        <span class="text-gray-600">
                            @if($device->revoked_at)
                                {{ __('Spärrad :date', ['date' => $device->revoked_at->format('Y-m-d')]) }}
                            @elseif($device->paired_at)
                                {{ __('Kopplad. Senast i kontakt :time', ['time' => $device->last_seen_at?->format('Y-m-d H:i') ?? '–']) }}
                            @else
                                {{ __('Väntar på koppling') }}
                            @endif
                        </span>
                        @if($canManage && ! $device->revoked_at)
                            <span class="flex gap-3 font-semibold">
                                @if($device->paired_at)
                                    <button type="button" wire:click="replace({{ $device->id }})" wire:confirm="{{ __('Koppla kiosken igen? Den gamla kopplingen spärras och du får en ny kod. Incheckningar som inte hunnit skickas från surfplattan tas inte emot.') }}" class="text-accent-700 underline">{{ __('Koppla igen') }}</button>
                                @else
                                    <button type="button" wire:click="renewCode({{ $device->id }})" class="text-accent-700 underline">{{ __('Ny kopplingskod') }}</button>
                                @endif
                                <button type="button" wire:click="revoke({{ $device->id }})" wire:confirm="{{ __('Spärra kiosken? Den kan inte längre checka in någon. Händelser som inte hunnit skickas från den tas inte emot.') }}" class="text-red-800 underline">{{ __('Spärra') }}</button>
                            </span>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-gray-600">{{ __('Ingen kiosk ännu.') }}</li>
                @endforelse
            </ul>
            @if($canManage && ! $location->archived_at && $location->kiosk_enabled)
                @if($addingDeviceTo === $location->id)
                    <form wire:submit="registerDevice" class="mt-4 flex flex-wrap items-end gap-3">
                        <x-ui.field for="device-name-{{ $location->id }}" :label="__('Namn på kiosken')" error="deviceName">
                            <input id="device-name-{{ $location->id }}" wire:model="deviceName" class="{{ $input }}" placeholder="{{ __('Entrén') }}" required>
                        </x-ui.field>
                        <x-button type="submit">{{ __('Lägg till kiosk') }}</x-button>
                    </form>
                @else
                    <button type="button" wire:click="addDevice({{ $location->id }})" class="mt-4 text-sm font-semibold text-accent-700 underline">{{ __('Lägg till kiosk') }}</button>
                @endif
            @endif
        </x-ui.card>
    @empty
        @if($editing === null)
            <x-ui.empty-state :title="__('Inga lokaler ännu')">{{ __('Lägg till lokalen där personalen arbetar.') }}</x-ui.empty-state>
        @endif
    @endforelse
</div>
