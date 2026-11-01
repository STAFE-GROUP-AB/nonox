@php
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $cell = 'w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm';
    $rates = [2500 => '25 %', 1200 => '12 %', 600 => '6 %', 0 => __('0 %')];
    $kr = fn (int $ore): Money => Money::ofOre($ore);
@endphp

<div class="space-y-6">

    @if($errors->has('invoice'))
        <div class="rounded-xl border border-accent-200 bg-accent-50 px-5 py-4 text-sm text-accent-800" role="alert">{{ $errors->first('invoice') }}</div>
    @endif

    @if($credit)
        <p class="rounded-lg bg-gray-100 px-4 py-3 text-sm text-gray-700">{{ __('Det här är en kreditfaktura. Beloppen dras av från kundens faktura när den skapas.') }}</p>
    @endif

    <x-ui.card>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="invoice_customer" :label="__('Kund')" error="customer_id" class="sm:col-span-2">
                @if($customers->isEmpty())
                    <p class="mt-2 text-sm text-gray-600">{{ __('Du har inga kunder ännu.') }} <a href="{{ route('invoicing.customers') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Lägg till en kund') }}</a></p>
                @else
                    <select id="invoice_customer" class="{{ $input }} mt-1" wire:model="customerId" @disabled($credit)>
                        <option value="">{{ __('Välj kund…') }}</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->number }} {{ $customer->name }}</option>
                        @endforeach
                    </select>
                    <label class="mt-2 flex items-start gap-2 text-sm text-gray-800">
                        <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-ink focus:ring-accent-500" wire:model.live="reverseCharge" @disabled($credit)>
                        <span>{{ __('Omvänd betalningsskyldighet för byggtjänster') }}@if($reverseCharge)<span class="block text-xs text-gray-500">{{ __('Fakturan blir utan moms och försäljningen bokförs på 3231. Köparens och ert momsregistreringsnummer skrivs ut på fakturan.') }}</span>@endif</span>
                    </label>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <x-ui.field for="invoice_foreign_sale" :label="__('Försäljning utomlands')" error="foreign_sale">
                            <select id="invoice_foreign_sale" class="{{ $input }} mt-1" wire:model.live="foreignSale" @disabled($credit)>
                                <option value="">{{ __('Nej, försäljning i Sverige') }}</option>
                                @foreach(App\Domain\Invoicing\ForeignSale::cases() as $sale)
                                    <option value="{{ $sale->value }}">{{ __($sale->label()) }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field for="invoice_tax_reduction" :label="__('ROT- eller RUT-avdrag')" error="tax_reduction">
                            <select id="invoice_tax_reduction" class="{{ $input }} mt-1" wire:model.live="taxReduction" @disabled($credit)>
                                <option value="">{{ __('Inget avdrag') }}</option>
                                <option value="ROT">{{ __('ROT-avdrag') }}</option>
                                <option value="RUT">{{ __('RUT-avdrag') }}</option>
                            </select>
                        </x-ui.field>
                        @if($taxReduction === 'ROT')
                            <x-ui.field for="invoice_property" :label="__('Fastighetsbeteckning eller BRF')" error="property_designation"><input id="invoice_property" type="text" class="{{ $input }} mt-1" wire:model="propertyDesignation" placeholder="{{ __('Till exempel Uppsala Kåbo 1:23') }}"></x-ui.field>
                        @endif
                    </div>
                    @if($foreignSale === 'EU_CONSUMER_OSS')
                        <p class="mt-2 text-xs text-gray-500">{{ __('Raderna får momssatsen i kundens land när du sparar, och momsen bokförs på 2619 för OSS-deklarationen i stället för den svenska momsdeklarationen.') }}</p>
                    @elseif($foreignSale !== '')
                        <p class="mt-2 text-xs text-gray-500">{{ __('Fakturan blir utan svensk moms och försäljningen bokförs på :account. Texten :text skrivs ut på fakturan.', ['account' => App\Domain\Invoicing\ForeignSale::from($foreignSale)->account(), 'text' => App\Domain\Invoicing\ForeignSale::from($foreignSale)->invoiceText()]) }}</p>
                    @endif
                    @if($taxReduction !== '')
                        <p class="mt-2 text-xs text-gray-500">{{ __('Bocka i Arbete på raderna som är arbetskostnad. Avdraget räknas på arbetskostnaden inklusive moms när du sparar, och kunden betalar resten.') }}</p>
                    @endif
                @endif
            </x-ui.field>
            <x-ui.field for="invoice_date" :label="__('Fakturadatum')" error="invoice_date">
                <input id="invoice_date" type="date" class="{{ $input }} mt-1" wire:model="invoiceDate">
            </x-ui.field>
            @unless($credit)
                <x-ui.field for="invoice_due" :label="__('Förfallodag')" error="due_date" :hint="__('Tomt: kundens betalningsvillkor.')">
                    <input id="invoice_due" type="date" class="{{ $input }} mt-1" wire:model="dueDate">
                </x-ui.field>
            @endunless
            <x-ui.field for="invoice_delivery" :label="__('Leveransdatum')" error="delivery_date" :hint="__('Tomt: samma som fakturadatum.')">
                <input id="invoice_delivery" type="date" class="{{ $input }} mt-1" wire:model="deliveryDate">
            </x-ui.field>
            <x-ui.field for="invoice_your_reference" :label="__('Er referens')" error="your_reference">
                <input id="invoice_your_reference" type="text" class="{{ $input }} mt-1" wire:model="yourReference">
            </x-ui.field>
            <x-ui.field for="invoice_our_reference" :label="__('Vår referens')" error="our_reference">
                <input id="invoice_our_reference" type="text" class="{{ $input }} mt-1" wire:model="ourReference">
            </x-ui.field>
        </div>
    </x-ui.card>

    @include('livewire.invoicing.partials.rows', ['negative' => $credit, 'reverseCharge' => true, 'labour' => $taxReduction !== ''])

    <x-ui.card>
        <x-ui.field for="invoice_notes" :label="__('Meddelande på fakturan (frivilligt)')" error="notes">
            <textarea id="invoice_notes" rows="3" class="{{ $input }} mt-1" wire:model="notes" maxlength="2000"></textarea>
        </x-ui.field>
    </x-ui.card>

    <div class="flex flex-wrap items-center justify-end gap-3" x-data="{ confirming: false }">
        <a href="{{ route('invoicing.invoices.index') }}" class="text-sm text-gray-600 underline">{{ __('Avbryt') }}</a>
        <button type="button" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:click="save" wire:loading.attr="disabled">{{ __('Spara som utkast') }}</button>
        @if($canIssue)
            <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ $credit ? __('Skapa kreditfakturan') : __('Skapa fakturan') }}</x-button>
            <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3 text-sm">
                <span class="text-gray-700">{{ __('Fakturan får ett nummer, bokförs och kan sedan inte ändras, bara krediteras.') }}</span>
                <x-button type="button" wire:click="issue" x-on:click="confirming = false" wire:loading.attr="disabled">{{ __('Ja, skapa') }}</x-button>
                <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
            </span>
        @endif
    </div>
</div>
