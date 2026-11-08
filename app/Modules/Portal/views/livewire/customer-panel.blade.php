@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<x-ui.card :title="__('Kundportal')" :description="__('Kundens kontakter loggar in med en kod i mejlet och ser fakturor, kontoutdrag och offerter.')" data-testid="customer-portal-panel">
    @if($contacts->isEmpty())
        <x-ui.empty-state class="mt-4" :title="__('Ingen har tillgång ännu')">
            {{ __('Bjud in den som tar emot fakturorna hos kunden. Kundportalen är frivillig för kunden.') }}
        </x-ui.empty-state>
    @else
        <ul class="mt-4 divide-y divide-gray-100 text-sm">
            @foreach($contacts as $contact)
                <li wire:key="portal-contact-{{ $contact->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <span>
                        <span class="block font-medium text-gray-900">{{ $contact->identity?->name }}</span>
                        <span class="block text-gray-600">{{ $contact->identity?->email }} · {{ $contact->status->label() }} · {{ $contact->can_accept_quotes ? __('får godkänna offerter') : __('får inte godkänna offerter') }}@if($contact->last_seen_at) · {{ __('senast inne :date', ['date' => $contact->last_seen_at->setTimezone(config('app.timezone'))->format('Y-m-d')]) }}@endif</span>
                    </span>
                    @if($canManage)
                        <span class="flex flex-wrap gap-3">
                            <button type="button" class="font-medium text-gray-700 underline" wire:click="toggleQuotes({{ $contact->id }})" wire:confirm="{{ $contact->can_accept_quotes ? __('Ta bort rätten att godkänna offerter för :name?', ['name' => $contact->identity?->name]) : __('Låta :name godkänna offerter för kunden? Ett godkännande gör offerten till en order.', ['name' => $contact->identity?->name]) }}">{{ $contact->can_accept_quotes ? __('Får inte godkänna offerter') : __('Får godkänna offerter') }}</button>
                            <button type="button" class="font-medium text-gray-700 underline" wire:click="startEmailChange({{ $contact->id }})">{{ __('Ändra e-post') }}</button>
                            @if($contact->status->value !== 'DISABLED')
                                <button type="button" class="font-medium text-gray-700 underline" wire:click="disable({{ $contact->id }})" wire:confirm="{{ __('Stänga av :name? Personen loggas ut direkt och kan inte logga in igen förrän du bjuder in igen.', ['name' => $contact->identity?->name]) }}">{{ __('Stäng av') }}</button>
                            @endif
                            <button type="button" class="font-medium text-red-700 underline" wire:click="remove({{ $contact->id }})" wire:confirm="{{ __('Ta bort :name från kundportalen? Inloggningen tas bort. Meddelanden finns kvar med avsändaren ”Borttagen kontakt”.', ['name' => $contact->identity?->name]) }}">{{ __('Ta bort') }}</button>
                        </span>
                    @endif
                    @if($canManage && $changingEmailFor === $contact->id)
                        <form wire:submit="changeEmail" class="flex w-full flex-wrap items-end gap-3">
                            <x-ui.field for="portal-new-email" :label="__('Ny e-postadress')" error="email">
                                <input id="portal-new-email" type="email" class="{{ $input }} mt-1" wire:model="newEmail" autocomplete="off">
                            </x-ui.field>
                            <x-button wire:loading.attr="disabled" wire:confirm="{{ __('Ändra adressen? Personen loggas ut och loggar sedan in med den nya adressen.') }}">{{ __('Spara') }}</x-button>
                            <button type="button" class="text-sm font-medium text-gray-700 underline" wire:click="$set('changingEmailFor', null)">{{ __('Avbryt') }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if($canManage)
        @if(! $showForm)
            <x-button type="button" class="mt-4" wire:click="startInvite">{{ __('Bjud in') }}</x-button>
        @else
            <form wire:submit="invite" class="mt-4 grid gap-4 sm:grid-cols-2">
                @if($people->isNotEmpty())
                    <x-ui.field for="portal-person" :label="__('Kontaktperson i CRM')" error="crm_person_id" class="sm:col-span-2">
                        <select id="portal-person" class="{{ $input }} mt-1" wire:model.live="personId">
                            <option value="">{{ __('Annan person') }}</option>
                            @foreach($people as $person)
                                <option value="{{ $person['id'] }}" @disabled($person['blocked'])>{{ $person['name'] }}{{ $person['recipient'] ? ' ('.__('fakturamottagare').')' : '' }}{{ $person['blocked'] ? ' – '.__('Kontakta inte') : '' }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="portal-name" :label="__('Namn')" error="name">
                    <input id="portal-name" class="{{ $input }} mt-1" wire:model="name" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="portal-email" :label="__('E-post')" error="email">
                    <input id="portal-email" type="email" class="{{ $input }} mt-1" wire:model="email" autocomplete="off">
                </x-ui.field>
                <label class="flex items-start gap-3 text-sm sm:col-span-2">
                    <input type="checkbox" wire:model="canAcceptQuotes" class="mt-0.5 rounded border-gray-400">
                    <span>{{ __('Får godkänna offerter') }} <span class="block text-gray-600">{{ __('Ett godkännande i kundportalen gör offerten till en order. Ge rätten bara till den som får binda kunden.') }}</span></span>
                </label>
                <p class="text-sm text-gray-600 sm:col-span-2">{{ $customer->personal_number !== null ? __('Kunden är en privatperson: bara kundens egen e-postadress kan bjudas in.') : __('Personen får ett mejl med en länk som gäller i 14 dagar, och loggar in med en kod.') }}</p>
                <div class="flex gap-3 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Skicka inbjudan') }}</x-button>
                    <button type="button" class="text-sm font-medium text-gray-700 underline" wire:click="$set('showForm', false)">{{ __('Avbryt') }}</button>
                </div>
            </form>
        @endif
    @endif
</x-ui.card>
