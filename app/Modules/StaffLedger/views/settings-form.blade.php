@php($input = 'mt-1 block w-32 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm tabular-nums')
<form wire:submit="save" class="space-y-5">
    <x-ui.field for="sl-reminder" :label="__('Påminn om glömd utcheckning efter (timmar)')" :hint="__('Personen (om e-post finns) och cheferna får en påminnelse. Ingen checkas ut automatiskt.')" error="settings.reminder_after_hours">
        <input id="sl-reminder" type="number" min="1" max="48" wire:model="settings.reminder_after_hours" class="{{ $input }}">
    </x-ui.field>
    <x-ui.field for="sl-long" :label="__('Långt arbetspass efter (timmar)')" :hint="__('Visas som avvikelse i översikten.')" error="settings.long_shift_hours">
        <input id="sl-long" type="number" min="1" max="48" wire:model="settings.long_shift_hours" class="{{ $input }}">
    </x-ui.field>
    <x-ui.field for="sl-skew" :label="__('Tillåten skillnad i kioskens klocka (minuter)')" :hint="__('Större skillnad markeras på posten. Tiden ändras aldrig.')" error="settings.clock_skew_minutes">
        <input id="sl-skew" type="number" min="1" max="120" wire:model="settings.clock_skew_minutes" class="{{ $input }}">
    </x-ui.field>
    <p class="text-sm text-gray-600">{{ __('Vilka sätt att checka in som gäller (kiosk, QR-kod, mobil med platskontroll) väljs per lokal under Personalliggare, Lokaler.') }}
        @if(Route::has('staff-ledger.locations'))
            <a href="{{ route('staff-ledger.locations') }}" class="font-semibold text-accent-700 underline">{{ __('Öppna Lokaler') }}</a>
        @endif
    </p>
    <x-button type="submit">{{ __('Spara') }}</x-button>
</form>
