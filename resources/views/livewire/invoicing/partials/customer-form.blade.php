{{-- The customer form: the register's, and the CRM card's ledger fields (ADR-043). --}}
@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $fields = [
        'name' => [__('Namn'), 'sm:col-span-2'],
        'org_number' => [__('Organisationsnummer'), ''],
        'personal_number' => [__('Personnummer (privatperson)'), ''],
        'vat_number' => [__('Momsregistreringsnummer'), ''],
        'email' => [__('E-post för fakturor'), ''],
        'phone' => [__('Telefon'), ''],
        'payment_terms_days' => [__('Betalningsvillkor, dagar'), ''],
        'address_line1' => [__('Adress'), 'sm:col-span-2'],
        'address_line2' => [__('Adress, rad 2'), 'sm:col-span-2'],
        'postal_code' => [__('Postnummer'), ''],
        'city' => [__('Ort'), ''],
        'country' => [__('Land (landskod, t.ex. SE, DE, US)'), ''],
        'currency' => [__('Valuta för fakturor (t.ex. SEK, EUR, USD)'), ''],
    ];
@endphp
<x-ui.card :title="$formTitle ?? ($editingId ? __('Ändra kund') : __('Ny kund :number', ['number' => $nextNumber]))">
    @if($editingId)
        <p class="mb-4 text-sm"><a href="{{ route('privacy.export.customer', $editingId) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner registerutdrag (JSON)') }}</a> <span class="text-gray-500">· {{ __('när personen begär ut sina uppgifter enligt GDPR') }}</span></p>
    @endif
    <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
        @foreach($fields as $field => [$label, $span])
            <x-ui.field :for="'customer_'.$field" :label="$label" :error="$field" class="{{ $span }}">
                <input id="customer_{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" class="{{ $input }} mt-1" wire:model{{ $field === 'org_number' ? '.blur' : '' }}="form.{{ $field }}" @if($field === 'payment_terms_days') inputmode="numeric" @endif>
            </x-ui.field>
            @if($field === 'personal_number')
                @include('livewire.partials.company-lookup', ['class' => 'sm:col-span-2'])
            @endif
        @endforeach
        @if($editingId && filled($form['vat_number'] ?? '') && ! str_starts_with(strtoupper($form['vat_number']), 'SE'))
            <div class="flex flex-wrap items-center gap-3 rounded-lg bg-gray-50 px-4 py-3 text-sm sm:col-span-2">
                @if($vatCheck)
                    <span @class(['font-medium', 'text-gray-900' => $vatCheck->valid, 'text-accent-700' => ! $vatCheck->valid])>
                        {{ $vatCheck->valid ? __('Giltigt i VIES') : __('Ogiltigt i VIES') }} {{ $vatCheck->vat_number }}
                    </span>
                    <span class="text-gray-600">{{ __('Kontrollerat :date', ['date' => $vatCheck->checked_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}@if($vatCheck->request_identifier) · {{ __('Konsultationsnummer :id', ['id' => $vatCheck->request_identifier]) }}@endif</span>
                    @if($vatCheck->name)<span class="w-full text-gray-600">{{ $vatCheck->name }}</span>@endif
                @else
                    <span class="text-gray-600">{{ __('Numret är inte kontrollerat i EU:s register VIES.') }}</span>
                @endif
                <button type="button" class="ml-auto font-medium text-accent-600 hover:text-accent-700" wire:click="checkVatNumber" wire:loading.attr="disabled">{{ __('Kontrollera i VIES') }}</button>
            </div>
        @endif
        <x-ui.field for="customer_foreign_sale" :label="__('Försäljning utomlands')" error="foreign_sale" :hint="__('Nya fakturor till kunden blir utan svensk moms och bokförs på rätt konto.')">
            <select id="customer_foreign_sale" class="{{ $input }} mt-1" wire:model="foreignSale">
                <option value="">{{ __('Nej, försäljning i Sverige') }}</option>
                @foreach(App\Domain\Invoicing\ForeignSale::cases() as $sale)
                    <option value="{{ $sale->value }}">{{ __($sale->label()) }}</option>
                @endforeach
            </select>
        </x-ui.field>
        <label class="flex items-start gap-3 text-sm text-gray-800 sm:col-span-2">
            <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model="reverseCharge">
            <span>{{ __('Omvänd betalningsskyldighet för byggtjänster') }}<span class="block text-xs text-gray-500">{{ __('Kunden är ett byggföretag som själv redovisar momsen på byggtjänster ni säljer. Nya fakturor till kunden blir utan moms.') }}</span></span>
        </label>
        @if($peppolOn ?? false)
            <x-ui.field for="customer_invoice_delivery" :label="__('Skicka fakturor')" error="invoice_delivery">
                <select id="customer_invoice_delivery" class="{{ $input }} mt-1" wire:model="form.invoice_delivery">
                    <option value="EMAIL">{{ __('Med e-post (PDF)') }}</option>
                    <option value="PEPPOL">{{ __('Fakturera via Peppol (e-faktura)') }}</option>
                </select>
            </x-ui.field>
            <x-ui.field for="customer_peppol_id" :label="__('Kundens Peppol-ID')" error="peppol_id" :hint="__('Till exempel 0007:5561234567 för ett svenskt organisationsnummer. Lämnas tomt för en svensk kund används organisationsnumret.')">
                <input id="customer_peppol_id" type="text" class="{{ $input }} mt-1" wire:model="form.peppol_id" autocomplete="off" spellcheck="false">
            </x-ui.field>
        @endif
        <div class="flex items-center gap-4 sm:col-span-2">
            <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
            <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
            @if($editingId)
                @if(App\Models\Customer::query()->whereKey($editingId)->whereNotNull('processing_restricted_at')->exists())
                    <button type="button" class="ms-auto text-sm text-accent-700 underline" wire:click="restrict(false)" wire:confirm="{{ __('Häva begränsningen? Uppgifterna kan då användas för nya dokument igen.') }}">{{ __('Häv begränsad behandling') }}</button>
                @else
                    <button type="button" class="ms-auto text-sm text-accent-700 underline" wire:click="restrict" wire:confirm="{{ __('Begränsa behandlingen enligt GDPR? Uppgifterna sparas men kan inte användas för nya dokument förrän begränsningen hävs.') }}">{{ __('Begränsa behandling') }}</button>
                @endif
                <button type="button" class="text-sm text-accent-700 underline" wire:click="erase" wire:confirm="{{ __('Radera uppgifterna enligt GDPR? Finns de i bokföringen anonymiseras de i registret i stället; fakturorna behåller sina uppgifter.') }}">{{ __('Radera enligt GDPR') }}</button>
            @endif

        </div>
    </form>
</x-ui.card>
