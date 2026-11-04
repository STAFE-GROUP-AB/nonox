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
    <div class="sm:col-span-2">
        <label class="flex items-start gap-3 text-sm text-gray-700">
            <input id="crm-registry-monitoring" type="checkbox" wire:model="settings.registry_monitoring" class="mt-1 size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500">
            <span>
                <span class="block font-medium text-gray-900">{{ __('Bevaka kunderna i företagsregistret') }}</span>
                <span class="block text-gray-600">{{ __('När SCB eller Bolagsverket har nya uppgifter om en kund med organisationsnummer, till exempel ny adress, nytt namn, konkurs eller likvidation, får den ansvarige en uppgift. Nya styrelser och VD, betalningsanmärkningar och kreditbetyg kräver en kreditupplysningstjänst och bevakas inte än.') }}</span>
            </span>
        </label>
        <label class="mt-3 flex items-start gap-3 text-sm text-gray-700">
            <input id="crm-registry-monitoring-prospects" type="checkbox" wire:model="settings.registry_monitoring_prospects" class="mt-1 size-5 rounded border-gray-300 text-accent-600 focus:ring-accent-500">
            <span>
                <span class="block font-medium text-gray-900">{{ __('Bevaka även prospekt') }}</span>
                <span class="block text-gray-600">{{ __('Ändringar hos prospekt blir också uppgifter. Avstängt från början, eftersom ett prospekt ännu inte är någon ni gör affärer med.') }}</span>
            </span>
        </label>
    </div>
    <div class="sm:col-span-2">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
    </div>
</form>
