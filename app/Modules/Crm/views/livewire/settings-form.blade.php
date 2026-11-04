@php($input = 'mt-1 block w-28 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs tabular-nums')
<form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
    <x-ui.field for="crm-inactive" :label="__('Inaktiv efter, månader utan faktura')" error="settings.months_to_inactive" :hint="__('En aktiv kund blir inaktiv när den inte har fått någon faktura på så här många månader. Standard är 12.')">
        <input id="crm-inactive" type="number" min="1" max="120" inputmode="numeric" wire:model="settings.months_to_inactive" class="{{ $input }}">
    </x-ui.field>
    <x-ui.field for="crm-prune" :label="__('Gallra efter, månader utan aktivitet')" error="settings.prune_after_months" :hint="__('Kontaktpersoner och prospekt utan aktivitet på så här länge raderas. De visas under Att göra 30 dagar innan. Standard är 24.')">
        <input id="crm-prune" type="number" min="6" max="120" inputmode="numeric" wire:model="settings.prune_after_months" class="{{ $input }}">
    </x-ui.field>
    <x-ui.field for="crm-suppression" :label="__('Granska spärrlistan, år')" error="settings.suppression_review_years" :hint="__('Spärrlistan över personer som har invänt mot att bli kontaktade visas under Att göra för granskning när en rad inte har granskats på så här många år. Standard är 3.')">
        <input id="crm-suppression" type="number" min="1" max="10" inputmode="numeric" wire:model="settings.suppression_review_years" class="{{ $input }}">
    </x-ui.field>
    <x-ui.field for="crm-win-back" :label="__('Vinn tillbaka efter, månader')" error="settings.win_back_months" :hint="__('En förlorad affär eller kund får ett datum så här många månader fram. Då får den ansvarige en uppgift att höra av sig igen. Datumet kan ändras eller tas bort på varje affär. Standard är 6.')">
        <input id="crm-win-back" type="number" min="1" max="36" inputmode="numeric" wire:model="settings.win_back_months" class="{{ $input }}">
    </x-ui.field>
    {{-- ADR-049: what the users' calendars may get. --}}
    <fieldset class="space-y-3 sm:col-span-2">
        <legend class="text-sm font-semibold text-ink">{{ __('Kalender') }}</legend>
        <label class="flex items-start gap-3 text-sm text-gray-800">
            <input type="checkbox" class="mt-0.5 size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model="settings.calendar_details" aria-describedby="crm-calendar-details-hint">
            <span>
                {{ __('Användarna får visa detaljer i sina kalendrar') }}
                <span id="crm-calendar-details-hint" class="block text-gray-600">{{ __('Rubrik, plats, anteckning och personer i kalenderlänkar och kopplade kalendrar, om användaren själv väljer det. Utan: bara typ och företag, till exempel "Möte: Företaget AB".') }}</span>
            </span>
        </label>
        @if($calendarProviders)
            <label class="flex items-start gap-3 text-sm text-gray-800">
                <input type="checkbox" class="mt-0.5 size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model="settings.calendar_sync" aria-describedby="crm-calendar-sync-hint">
                <span>
                    {{ __('Användarna får koppla sin Google- eller Microsoft-kalender') }}
                    <span id="crm-calendar-sync-hint" class="block text-gray-600">{{ __('Aktiviteterna kopieras då till Google eller Microsoft, som kan lagra dem utanför EU. Slå bara på det om företaget godkänner det, och ta upp det i företagets egen registerförteckning.') }}</span>
                </span>
            </label>
        @endif
    </fieldset>
    <div class="sm:col-span-2">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
    </div>
</form>
