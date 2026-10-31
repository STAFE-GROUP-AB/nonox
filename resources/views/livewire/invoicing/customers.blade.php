@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
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
    ];
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra kund') : __('Ny kund :number', ['number' => $nextNumber])">
            @if($editingId)
                <p class="mb-4 text-sm"><a href="{{ route('privacy.export.customer', $editingId) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner registerutdrag (JSON)') }}</a> <span class="text-gray-500">· {{ __('när personen begär ut sina uppgifter enligt GDPR') }}</span></p>
            @endif
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                @foreach($fields as $field => [$label, $span])
                    <x-ui.field :for="'customer_'.$field" :label="$label" :error="$field" class="{{ $span }}">
                        <input id="customer_{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" class="{{ $input }} mt-1" wire:model="form.{{ $field }}" @if($field === 'payment_terms_days') inputmode="numeric" @endif>
                    </x-ui.field>
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
                    <input type="checkbox" class="mt-0.5 rounded border-gray-300 text-ink focus:ring-accent-500" wire:model="reverseCharge">
                    <span>{{ __('Omvänd betalningsskyldighet för byggtjänster') }}<span class="block text-xs text-gray-500">{{ __('Kunden är ett byggföretag som själv redovisar momsen på byggtjänster ni säljer. Nya fakturor till kunden blir utan moms.') }}</span></span>
                </label>
                <div class="flex items-center gap-4 sm:col-span-2">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                    @if($editingId)
                        <button type="button" class="ms-auto text-sm text-accent-700 underline" wire:click="erase" wire:confirm="{{ __('Radera uppgifterna enligt GDPR? Finns de i bokföringen anonymiseras de i registret i stället; fakturorna behåller sina uppgifter.') }}">{{ __('Radera enligt GDPR') }}</button>
                    @endif

                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <input type="search" class="{{ $input }} max-w-sm" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på namn, nummer eller e-post') }}" aria-label="{{ __('Sök kund') }}">
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Ny kund') }}</x-button>
            @endif
        </div>

        @if($customers->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$search === '' ? __('Inga kunder ännu') : __('Ingen kund matchar sökningen')">
                {{ __('Lägg till de kunder du fakturerar. Uppgifterna hamnar på fakturan, och betalningsvillkoret styr förfallodagen.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100">
                @foreach($customers as $customer)
                    <li wire:key="customer-{{ $customer->id }}" class="flex flex-wrap items-center gap-4 py-3">
                        <span class="w-14 flex-none font-semibold tabular-nums text-gray-900">{{ $customer->number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900">{{ $customer->name }}</span>
                            <span class="block text-sm text-gray-600">{{ collect([$customer->org_number, $customer->email, $customer->city])->filter()->implode(' · ') }}</span>
                        </span>
                        <span class="w-36 flex-none text-right text-sm">
                            <span class="block text-gray-500">{{ __('Fakturerat :year', ['year' => $year]) }}</span>
                            <x-ui.amount :money="App\Support\Money\Money::ofOre((int) $customer->invoiced_this_year)" class="font-medium text-gray-900" />
                        </span>
                        <span class="w-32 flex-none text-right text-sm">
                            <span class="block text-gray-500">{{ __('Obetalt') }}</span>
                            <x-ui.amount :money="App\Support\Money\Money::ofOre((int) $customer->unpaid)" class="font-medium text-gray-900" />
                        </span>
                        @if($canManage)
                            <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $customer->id }})">{{ __('Ändra') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $customers->links() }}</div>
        @endif
    </x-ui.card>
</div>
