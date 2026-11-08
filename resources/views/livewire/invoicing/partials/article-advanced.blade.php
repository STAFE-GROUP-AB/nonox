{{-- The Avancerad tab of the article form (ADR-039). Stock fields only while Lager is on. --}}
<x-ui.field for="article_status" :label="__('Status')" error="is_active" class="sm:col-span-6" :hint="__('En utgången artikel finns kvar på gamla fakturor men föreslås inte på nya.')">
    <select id="article_status" class="{{ $input }} mt-1 sm:max-w-xs" wire:model="form.is_active">
        <option value="1">{{ __('Aktiv') }}</option>
        <option value="0">{{ __('Utgången') }}</option>
    </select>
</x-ui.field>
<x-ui.field for="article_sku" :label="__('SKU')" error="sku" class="sm:col-span-2" :hint="__('Egen lagerkod, om den skiljer sig från artikelnumret.')">
    <input id="article_sku" type="text" class="{{ $input }} mt-1" wire:model="form.sku" maxlength="60">
</x-ui.field>
<x-ui.field for="article_gtin" :label="__('EAN/GTIN')" error="gtin" class="sm:col-span-2" :hint="__('Streckkoden på förpackningen, 8 eller 13 siffror. Kontrollsiffran kontrolleras.')">
    <input id="article_gtin" type="text" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.gtin" maxlength="14">
</x-ui.field>
<x-ui.field for="article_scanner" :label="__('Skannerkod')" error="scanner_code" class="sm:col-span-2" :hint="__('En annan kod som skannern läser, till exempel er egen etikett.')">
    <input id="article_scanner" type="text" class="{{ $input }} mt-1" wire:model="form.scanner_code" maxlength="64">
</x-ui.field>
<x-ui.field for="article_brand" :label="__('Varumärke')" error="brand" class="sm:col-span-3">
    <input id="article_brand" type="text" class="{{ $input }} mt-1" wire:model="form.brand" maxlength="100">
</x-ui.field>
<x-ui.field for="article_model" :label="__('Modell')" error="model" class="sm:col-span-3">
    <input id="article_model" type="text" class="{{ $input }} mt-1" wire:model="form.model" maxlength="100">
</x-ui.field>
<x-ui.field for="article_description" :label="__('Beskrivning')" error="description" class="sm:col-span-6">
    <textarea id="article_description" rows="4" class="{{ $input }} mt-1" wire:model="form.description" maxlength="5000"></textarea>
</x-ui.field>
<fieldset class="sm:col-span-6">
    <legend class="text-sm font-medium text-gray-700">{{ __('Egenskaper') }}</legend>
    <p class="text-sm text-gray-500">{{ __('Till exempel storlek: M, skick: begagnad, årsmodell: 2021.') }}</p>
    @foreach(($form['properties'] ?? []) as $index => $property)
        <div class="mt-2 grid grid-cols-5 gap-2" wire:key="property-{{ $index }}">
            <input type="text" aria-label="{{ __('Egenskap') }}" placeholder="{{ __('Egenskap') }}" class="{{ $input }} col-span-2" wire:model="form.properties.{{ $index }}.key" maxlength="60">
            <input type="text" aria-label="{{ __('Värde') }}" placeholder="{{ __('Värde') }}" class="{{ $input }} col-span-2" wire:model="form.properties.{{ $index }}.value" maxlength="255">
            <button type="button" class="text-sm text-gray-700 underline" wire:click="removeProperty({{ $index }})">{{ __('Ta bort') }}</button>
        </div>
    @endforeach
    <button type="button" class="mt-2 text-sm font-semibold text-accent-700 underline" wire:click="addProperty">{{ __('Lägg till egenskap') }}</button>
</fieldset>
<x-ui.field for="article_colours" :label="__('Färger')" error="colours" class="sm:col-span-3" :hint="__('Skilj med komma.')">
    <input id="article_colours" type="text" class="{{ $input }} mt-1" wire:model="form.colours">
</x-ui.field>
<x-ui.field for="article_materials" :label="__('Material')" error="materials" class="sm:col-span-3" :hint="__('Skilj med komma.')">
    <input id="article_materials" type="text" class="{{ $input }} mt-1" wire:model="form.materials">
</x-ui.field>
<x-ui.field for="article_weight" :label="__('Vikt (gram)')" error="weight_grams" class="sm:col-span-2">
    <input id="article_weight" type="number" min="0" class="{{ $input }} mt-1 tabular-nums" wire:model="form.weight_grams">
</x-ui.field>
<x-ui.field for="article_length" :label="__('Längd (mm)')" error="length_mm" class="sm:col-span-1">
    <input id="article_length" type="number" min="0" class="{{ $input }} mt-1 tabular-nums" wire:model="form.length_mm">
</x-ui.field>
<x-ui.field for="article_width" :label="__('Bredd (mm)')" error="width_mm" class="sm:col-span-1">
    <input id="article_width" type="number" min="0" class="{{ $input }} mt-1 tabular-nums" wire:model="form.width_mm">
</x-ui.field>
<x-ui.field for="article_height" :label="__('Höjd (mm)')" error="height_mm" class="sm:col-span-2">
    <input id="article_height" type="number" min="0" class="{{ $input }} mt-1 tabular-nums" wire:model="form.height_mm">
</x-ui.field>
<x-ui.field for="article_purchase" :label="__('Inköpspris exkl. moms')" error="purchase_price" class="sm:col-span-2" :hint="__('Används som kostnad när inget annat pris anges vid inleveransen.')">
    <input id="article_purchase" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.purchase_price" placeholder="89,00">
</x-ui.field>
<x-ui.field for="article_vat_treatment" :label="__('Momshantering')" error="vat_treatment" class="sm:col-span-4" :hint="__('Vinstmarginalbeskattning gäller begagnade varor som köpts utan moms. Beskattningsunderlaget hör då till ruta 07 i momsdeklarationen.')">
    <select id="article_vat_treatment" class="{{ $input }} mt-1" wire:model="form.vat_treatment">
        <option value="STANDARD">{{ __('Vanlig moms') }}</option>
        <option value="VMB">{{ __('Vinstmarginalbeskattning (VMB)') }}</option>
    </select>
</x-ui.field>
<x-ui.field for="article_supplier" :label="__('Leverantör')" error="default_supplier_id" class="sm:col-span-3">
    <select id="article_supplier" class="{{ $input }} mt-1" wire:model="form.default_supplier_id">
        <option value="">{{ __('Ingen') }}</option>
        @foreach($suppliers as $supplier)
            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
        @endforeach
    </select>
</x-ui.field>
<x-ui.field for="article_supplier_number" :label="__('Leverantörens artikelnummer')" error="supplier_article_number" class="sm:col-span-3">
    <input id="article_supplier_number" type="text" class="{{ $input }} mt-1" wire:model="form.supplier_article_number" maxlength="60">
</x-ui.field>
@if($inventory)
    <fieldset class="grid gap-4 rounded-2xl bg-stone-50 p-4 sm:col-span-6 sm:grid-cols-6">
        <legend class="sr-only">{{ __('Lager') }}</legend>
        <label class="flex items-start gap-3 sm:col-span-6">
            <input type="checkbox" class="mt-1 rounded-sm border-stone-300 text-ink focus:ring-accent-500" wire:model.live="form.is_stock_item">
            <span>
                <span class="block font-medium text-ink">{{ __('Lagerförd') }}</span>
                <span class="block text-sm text-gray-600">{{ __('Saldot följs per lagerställe, och artikeln kan tas emot, plockas och inventeras.') }}</span>
            </span>
        </label>
        @if($form['is_stock_item'] ?? false)
            <x-ui.field for="article_location" :label="__('Standardplats')" error="default_location_id" class="sm:col-span-2">
                <select id="article_location" class="{{ $input }} mt-1" wire:model="form.default_location_id">
                    <option value="">{{ __('Ingen') }}</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="article_reorder_point" :label="__('Beställningspunkt')" error="reorder_point" class="sm:col-span-2" :hint="__('När saldot når hit syns artikeln under Behöver beställas.')">
                <input id="article_reorder_point" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.reorder_point">
            </x-ui.field>
            <x-ui.field for="article_reorder_quantity" :label="__('Beställ antal')" error="reorder_quantity" class="sm:col-span-2">
                <input id="article_reorder_quantity" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.reorder_quantity">
            </x-ui.field>
            <x-ui.field for="article_stock_class" :label="__('Lagerslag')" error="stock_class" class="sm:col-span-2" :hint="__('Styr kontona för lagervärdet vid bokslutet: råvaror (1410) eller handelsvaror (1460). Tomt = företagets standard.')">
                <select id="article_stock_class" class="{{ $input }} mt-1" wire:model="form.stock_class">
                    <option value="">{{ __('Företagets standard') }}</option>
                    @foreach(\App\Modules\Inventory\Domain\StockClass::cases() as $class)
                        <option value="{{ $class->value }}">{{ $class->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="article_nrv" :label="__('Nettoförsäljningsvärde per st')" error="net_realisable_value" class="sm:col-span-2" :hint="__('Valfritt. Vad varan kan säljas för minus försäljningskostnader. Är det lägre än inköpspriset värderas lagret till det vid bokslutet.')">
                <input id="article_nrv" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.net_realisable_value">
            </x-ui.field>
            <x-ui.field for="article_nrv_note" :label="__('Varför')" error="net_realisable_value_note" class="sm:col-span-4">
                <input id="article_nrv_note" type="text" class="{{ $input }} mt-1" wire:model="form.net_realisable_value_note" maxlength="255" placeholder="{{ __('Till exempel: förra årets modell, säljs på rea') }}">
            </x-ui.field>
        @endif
    </fieldset>
@endif
