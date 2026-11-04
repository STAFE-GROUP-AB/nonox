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
    <x-ui.field for="crm-privacy" :label="__('Länk till er integritetspolicy')" error="privacy_url" class="sm:col-span-2" :hint="__('Visas i varje webbformulär och utskick. Krävs innan ett formulär kan publiceras eller ett utskick skickas.')">
        <input id="crm-privacy" type="url" placeholder="https://" wire:model="privacyUrl" class="block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs">
    </x-ui.field>
    <div class="sm:col-span-2">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
    </div>
</form>
