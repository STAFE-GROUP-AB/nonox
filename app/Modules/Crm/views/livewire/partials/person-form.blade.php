@php($input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<x-ui.card :title="$editingPersonId ? __('Ändra person') : __('Ny person')">
    @if($editingPersonId)
        <p class="mb-4 text-sm"><a href="{{ route('privacy.export.crmPerson', $editingPersonId) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner registerutdrag (JSON)') }}</a> <span class="text-gray-500">· {{ __('när personen begär ut sina uppgifter enligt GDPR') }}</span></p>
    @endif
    <form wire:submit="savePerson" class="grid gap-4 sm:grid-cols-2">
        <x-ui.field for="person_name" :label="__('Namn')" error="name" class="sm:col-span-2">
            <input id="person_name" type="text" class="{{ $input }} mt-1" wire:model="personForm.name" autocomplete="off" required>
        </x-ui.field>
        <x-ui.field for="person_title" :label="__('Titel')" error="title">
            <input id="person_title" type="text" class="{{ $input }} mt-1" wire:model="personForm.title">
        </x-ui.field>
        @if($withRole ?? false)
            <x-ui.field for="person_role" :label="__('Roll hos kunden')" error="role">
                <select id="person_role" class="{{ $input }} mt-1" wire:model="personForm.role">
                    @foreach(App\Modules\Crm\Domain\PersonRole::cases() as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        @endif
        <x-ui.field for="person_email" :label="__('E-post')" error="email">
            <input id="person_email" type="email" class="{{ $input }} mt-1" wire:model="personForm.email">
        </x-ui.field>
        <x-ui.field for="person_mobile" :label="__('Mobil')" error="mobile">
            <input id="person_mobile" type="tel" class="{{ $input }} mt-1" wire:model="personForm.mobile">
        </x-ui.field>
        <x-ui.field for="person_phone" :label="__('Telefon')" error="phone">
            <input id="person_phone" type="tel" class="{{ $input }} mt-1" wire:model="personForm.phone">
        </x-ui.field>
        <x-ui.field for="person_basis" :label="__('Rättslig grund (GDPR)')" error="legal_basis" class="sm:col-span-2" :hint="App\Modules\Crm\Domain\LegalBasis::tryFrom((string) ($personForm['legal_basis'] ?? ''))?->hint()">
            <select id="person_basis" class="{{ $input }} mt-1" wire:model.live="personForm.legal_basis">
                @foreach(App\Modules\Crm\Domain\LegalBasis::cases() as $basis)
                    <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                @endforeach
            </select>
        </x-ui.field>
        @if(($personForm['legal_basis'] ?? '') === 'LEGITIMATE_INTEREST')
            <p class="-mt-2 text-sm text-gray-600 sm:col-span-2">{{ __('Berättigat intresse kräver en intresseavvägning.') }} <a href="{{ route('manual.article', ['crm', 'gdpr-i-crm']) }}" class="font-medium text-accent-600 hover:text-accent-700" target="_blank">{{ __('Så gör du avvägningen') }}</a></p>
        @endif
        @if(($personForm['legal_basis'] ?? '') === 'CONSENT')
            <x-ui.field for="person_consent" :label="__('Datum för samtycket')" error="consent_at">
                <input id="person_consent" type="date" class="{{ $input }} mt-1" wire:model="personForm.consent_at">
            </x-ui.field>
        @endif
        <x-ui.field for="person_basis_note" :label="__('Anteckning om grunden (valfritt)')" error="legal_basis_note" class="sm:col-span-2">
            <input id="person_basis_note" type="text" class="{{ $input }} mt-1" wire:model="personForm.legal_basis_note">
        </x-ui.field>
        <x-ui.field for="person_informed" :label="__('Informerad den (valfritt)')" error="informed_at" :hint="__('Dagen personen fick information om hur ni behandlar uppgifterna (GDPR artikel 14), senast en månad efter att ni sparade dem.')">
            <input id="person_informed" type="date" class="{{ $input }} mt-1" wire:model="personForm.informed_at">
        </x-ui.field>
        <label class="flex min-h-11 items-start gap-3 text-sm text-gray-800 sm:col-span-2">
            <input type="checkbox" class="mt-0.5 size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model.live="personForm.do_not_contact">
            <span>{{ __('Kontakta inte') }}<span class="block text-xs text-gray-500">{{ __('Personen har invänt mot behandlingen eller begärt begränsad behandling. Inga nya samtal, möten, mejl eller uppgifter kan loggas för personen, bara anteckningar.') }}</span></span>
        </label>
        @if($personForm['do_not_contact'] ?? false)
            <x-ui.field for="person_dnc_reason" :label="__('Varför')" error="do_not_contact_reason">
                <select id="person_dnc_reason" class="{{ $input }} mt-1" wire:model="personForm.do_not_contact_reason">
                    <option value="">{{ __('Välj') }}</option>
                    @foreach(App\Modules\Crm\Domain\DoNotContact::cases() as $reason)
                        <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
        @endif
        @if($editingPersonId)
            <label class="flex min-h-11 items-center gap-3 text-sm text-gray-800 sm:col-span-2">
                <input type="checkbox" class="size-5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model="personForm.is_active">
                <span>{{ __('Aktiv kontakt') }}</span>
            </label>
            @if(($mentions ?? 0) > 0)
                <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:col-span-2">{{ trans_choice(':count aktivitet som inte är kopplad till personen nämner namnet. Den ändras inte vid radering; gå igenom den för hand.|:count aktiviteter som inte är kopplade till personen nämner namnet. De ändras inte vid radering; gå igenom dem för hand.', $mentions) }}</p>
            @endif
        @endif
        <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
            <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
            <button type="button" class="min-h-11 text-sm text-gray-600 underline" wire:click="closePanel">{{ __('Avbryt') }}</button>
            @if($editingPersonId)
                <button type="button" class="ms-auto min-h-11 text-sm text-accent-700 underline" wire:click="erasePerson" wire:confirm="{{ __('Radera personen enligt GDPR? Personen tas bort från alla kunder, och aktiviteterna som är kopplade till personen blir kvar utan namn och text. Fritext i andra aktiviteter som nämner personen ändras inte. Det går inte att ångra.') }}">{{ __('Radera enligt GDPR') }}</button>
            @endif
        </div>
    </form>
</x-ui.card>
