@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<form wire:submit="save" class="space-y-6">
    <x-ui.card :title="__('Jobbet')">
        <div class="grid gap-5 sm:grid-cols-6">
            <x-ui.field for="wo-customer" :label="__('Kund')" error="form.customer_id" class="sm:col-span-3">
                <select id="wo-customer" wire:model.live="form.customer_id" class="{{ $input }}" required>
                    <option value="">{{ __('Välj kund') }}</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}@if($customer->reverse_charge_construction) ({{ __('omvänd byggmoms') }})@endif</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="wo-title" :label="__('Rubrik')" error="form.title" class="sm:col-span-3" :hint="__('Till exempel Badrumsrenovering. Rubriken blir typ av arbete på fakturan.')">
                <input id="wo-title" wire:model="form.title" maxlength="255" class="{{ $input }}" required>
            </x-ui.field>
            <x-ui.field for="wo-description" :label="__('Beskrivning')" error="form.description" class="sm:col-span-6">
                <textarea id="wo-description" wire:model="form.description" rows="3" class="{{ $input }}"></textarea>
            </x-ui.field>
            <x-ui.field for="wo-start" :label="__('Planerad start')" error="form.planned_start" class="sm:col-span-2"><input id="wo-start" type="date" wire:model="form.planned_start" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="wo-end" :label="__('Planerat slut')" error="form.planned_end" class="sm:col-span-2"><input id="wo-end" type="date" wire:model="form.planned_end" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="wo-contract" :label="__('Standardavtal')" error="form.standard_contract" class="sm:col-span-2" :hint="__('Bara namnet skrivs ut, aldrig avtalets villkor.')">
                <select id="wo-contract" wire:model="form.standard_contract" class="{{ $input }}">
                    <option value="">{{ __('Inget') }}</option>
                    @foreach($contracts as $contract)<option value="{{ $contract }}">{{ $contract }}</option>@endforeach
                    <option value="Annat">{{ __('Annat') }}</option>
                </select>
            </x-ui.field>
            <fieldset class="sm:col-span-6">
                <legend class="text-sm font-medium text-gray-700">{{ __('Utförare') }}</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    @foreach($members as $member)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.user_ids" value="{{ $member->id }}" class="rounded border-gray-300 text-accent-600"> {{ $member->name }}</label>
                    @endforeach
                </div>
            </fieldset>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Platsen')">
        <div class="grid gap-5 sm:grid-cols-6">
            <x-ui.field for="wo-address" :label="__('Adress')" error="form.site_address_line1" class="sm:col-span-3"><input id="wo-address" wire:model="form.site_address_line1" class="{{ $input }}" autocomplete="off"></x-ui.field>
            <x-ui.field for="wo-postal" :label="__('Postnummer')" error="form.site_postal_code" class="sm:col-span-1"><input id="wo-postal" wire:model="form.site_postal_code" maxlength="10" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="wo-city" :label="__('Ort')" error="form.site_city" class="sm:col-span-2"><input id="wo-city" wire:model="form.site_city" class="{{ $input }}"></x-ui.field>
            <x-ui.field for="wo-access" :label="__('Portkod och nycklar')" error="form.access_notes" class="sm:col-span-6" :hint="__('Sparas krypterat och tas bort automatiskt när arbetsordern avslutas.')">
                <textarea id="wo-access" wire:model="form.access_notes" rows="2" class="{{ $input }}"></textarea>
            </x-ui.field>
            <label class="flex items-center gap-2 text-sm sm:col-span-6"><input type="checkbox" wire:model="form.is_construction" class="rounded border-gray-300 text-accent-600"> {{ __('Byggtjänst (påminnelse om sista fakturadag)') }}</label>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Pris')">
        <div class="grid gap-5 sm:grid-cols-6">
            <x-ui.field for="wo-price-form" :label="__('Prisform')" error="form.price_form" class="sm:col-span-3">
                <select id="wo-price-form" wire:model.live="form.price_form" class="{{ $input }}">
                    @foreach($priceForms as $priceForm)<option value="{{ $priceForm->value }}">{{ $priceForm->label() }}</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="wo-vat" :label="__('Moms')" error="form.vat_rate" class="sm:col-span-1">
                <select id="wo-vat" wire:model="form.vat_rate" class="{{ $input }}">
                    @foreach([2500, 1200, 600, 0] as $rate)<option value="{{ $rate }}">{{ $rate / 100 }} %</option>@endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="wo-markup" :label="__('Påslag på material, procent')" error="form.material_markup" class="sm:col-span-2"><input id="wo-markup" wire:model="form.material_markup" inputmode="decimal" class="{{ $input }}"></x-ui.field>
            @if($form['price_form'] === 'FIXED')
                <x-ui.field for="wo-sum" :label="__('Fast pris exklusive moms')" error="form.contract_sum" class="sm:col-span-3"><input id="wo-sum" wire:model="form.contract_sum" inputmode="decimal" class="{{ $input }} text-right tabular-nums"></x-ui.field>
                <x-ui.field for="wo-labour" :label="__('Varav arbete exklusive moms')" error="form.contract_labour" class="sm:col-span-3" :hint="__('Behövs för ROT och RUT: bara arbetskostnaden ger avdrag.')"><input id="wo-labour" wire:model="form.contract_labour" inputmode="decimal" class="{{ $input }} text-right tabular-nums"></x-ui.field>
            @elseif($form['price_form'] === 'ESTIMATE')
                <x-ui.field for="wo-approx" :label="__('Ungefärligt pris inklusive moms')" error="form.approximate_price" class="sm:col-span-3"><input id="wo-approx" wire:model="form.approximate_price" inputmode="decimal" class="{{ $input }} text-right tabular-nums"></x-ui.field>
                <x-ui.field for="wo-limit" :label="__('Annan avtalad gräns, procent')" error="form.approximate_limit" class="sm:col-span-3" :hint="__('Tomt betyder 15 procent, som i konsumenttjänstlagen.')"><input id="wo-limit" wire:model="form.approximate_limit" inputmode="decimal" class="{{ $input }}"></x-ui.field>
            @endif
            @foreach($kinds as $kind)
                <x-ui.field :for="'wo-rate-'.$kind->id" :label="__('Pris för :kind per :unit', ['kind' => mb_strtolower($kind->name), 'unit' => $kind->unit->label()])" :error="'form.rates.'.$kind->id" class="sm:col-span-2">
                    <input id="wo-rate-{{ $kind->id }}" wire:model="form.rates.{{ $kind->id }}" inputmode="decimal" placeholder="{{ $kind->default_rate_ore->format(false) }}" class="{{ $input }} text-right tabular-nums">
                </x-ui.field>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card :title="__('ROT och RUT')" :description="__('Gäller privatpersoner. Kan inte kombineras med omvänd byggmoms.')">
        <div class="grid gap-5 sm:grid-cols-6">
            <x-ui.field for="wo-tax" :label="__('Avdrag')" error="form.tax_reduction" class="sm:col-span-2">
                <select id="wo-tax" wire:model.live="form.tax_reduction" class="{{ $input }}">
                    <option value="">{{ __('Inget avdrag') }}</option>
                    <option value="ROT">{{ __('ROT-avdrag') }}</option>
                    <option value="RUT">{{ __('RUT-avdrag') }}</option>
                </select>
            </x-ui.field>
            @if($form['tax_reduction'] === 'ROT')
                <x-ui.field for="wo-property" :label="__('Fastighetsbeteckning')" error="form.property_designation" class="sm:col-span-4"><input id="wo-property" wire:model="form.property_designation" class="{{ $input }}" placeholder="{{ __('Till exempel Exempelstad Ekbacken 3:14') }}"></x-ui.field>
                <x-ui.field for="wo-brf" :label="__('Eller bostadsrättsföreningens organisationsnummer')" error="form.housing_association_org_number" class="sm:col-span-3"><input id="wo-brf" wire:model="form.housing_association_org_number" class="{{ $input }}"></x-ui.field>
                <x-ui.field for="wo-apartment" :label="__('Lägenhetsnummer')" error="form.apartment_number" class="sm:col-span-3"><input id="wo-apartment" wire:model="form.apartment_number" inputmode="numeric" maxlength="4" class="{{ $input }}"></x-ui.field>
            @endif
            @if($form['tax_reduction'] !== '')
                <div class="sm:col-span-6">
                    <p class="text-sm text-gray-700">{{ __('Flera köpare, till exempel makar, delar på avdraget efter sina andelar. Varje köpare har sitt eget tak.') }}</p>
                    @foreach($form['buyers'] as $index => $buyer)
                        <div class="mt-2 flex flex-wrap items-end gap-3" wire:key="buyer-{{ $index }}">
                            <x-ui.field :for="'buyer-'.$index" :label="__('Köpare')" :error="'form.buyers.'.$index.'.customer_id'">
                                <select id="buyer-{{ $index }}" wire:model="form.buyers.{{ $index }}.customer_id" class="{{ $input }}">
                                    <option value="">{{ __('Välj kund') }}</option>
                                    @foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field :for="'share-'.$index" :label="__('Andel, procent')" :error="'form.buyers.'.$index.'.share'"><input id="share-{{ $index }}" wire:model="form.buyers.{{ $index }}.share" inputmode="decimal" class="{{ $input }} w-24"></x-ui.field>
                            <x-secondary-button type="button" wire:click="removeBuyer({{ $index }})">{{ __('Ta bort') }}</x-secondary-button>
                        </div>
                    @endforeach
                    @error('form.buyers')<p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                    <x-secondary-button type="button" class="mt-2" wire:click="addBuyer">{{ __('Lägg till köpare') }}</x-secondary-button>
                </div>
            @endif
        </div>
    </x-ui.card>

    <div class="flex flex-wrap gap-3">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara arbetsordern') }}</x-button>
        <a href="{{ $locked ? route('workOrders.show', $workOrderId) : route('workOrders.index') }}" class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">{{ __('Avbryt') }}</a>
    </div>
</form>
