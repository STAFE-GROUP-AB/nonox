{{-- Units of the article (ADR-071 §12): stock, purchase and sales unit, the material's size and the conversions. --}}
@php
    $stockCode = $form['stock_unit_id'] !== '' ? ($units->firstWhere('id', (int) $form['stock_unit_id'])?->code ?? '') : ($form['unit'] ?? '');
@endphp
<fieldset class="grid gap-4 sm:col-span-6 sm:grid-cols-6">
    <legend class="text-sm font-medium text-gray-700">{{ __('Enheter') }}</legend>
    <p class="text-sm text-gray-500 sm:col-span-6">{{ __('Lagret räknas i lagerenheten. Artikeln kan köpas och säljas i andra enheter om det finns en omräkning, till exempel kubikmeter in och löpmeter ut. Enhet på fliken Enkel är den som skrivs på fakturan.') }} <a href="{{ route('invoicing.units') }}" class="font-medium text-accent-700 underline">{{ __('Hantera enheter') }}</a></p>
    <x-ui.field for="article_stock_unit" :label="__('Lagerenhet')" error="stock_unit_id" class="sm:col-span-2" :hint="__('Det varan räknas, flyttas och värderas i. För trä är det löpmeter eller styck, inte kubik.')">
        <select id="article_stock_unit" class="{{ $input }} mt-1" wire:model.live="form.stock_unit_id">
            <option value="">{{ __('Som Enhet på fliken Enkel') }}</option>
            @foreach($units as $unit)
                <option value="{{ $unit->id }}">{{ $unit->code }} ({{ $unit->name }}){{ $unit->isArchived() ? ' – '.__('arkiverad') : '' }}</option>
            @endforeach
        </select>
    </x-ui.field>
    <x-ui.field for="article_purchase_unit" :label="__('Inköpsenhet')" error="purchase_unit_id" class="sm:col-span-2" :hint="__('Enheten leverantören säljer i.')">
        <select id="article_purchase_unit" class="{{ $input }} mt-1" wire:model.live="form.purchase_unit_id">
            <option value="">{{ __('Som lagerenheten') }}</option>
            @foreach($units as $unit)
                <option value="{{ $unit->id }}">{{ $unit->code }} ({{ $unit->name }}){{ $unit->isArchived() ? ' – '.__('arkiverad') : '' }}</option>
            @endforeach
        </select>
    </x-ui.field>
    <x-ui.field for="article_sales_unit" :label="__('Försäljningsenhet')" error="sales_unit_id" class="sm:col-span-2" :hint="__('Enheten som skrivs på fakturan.')">
        <select id="article_sales_unit" class="{{ $input }} mt-1" wire:model.live="form.sales_unit_id">
            <option value="">{{ __('Som lagerenheten') }}</option>
            @foreach($units as $unit)
                <option value="{{ $unit->id }}">{{ $unit->code }} ({{ $unit->name }}){{ $unit->isArchived() ? ' – '.__('arkiverad') : '' }}</option>
            @endforeach
        </select>
    </x-ui.field>

    <p class="text-sm font-medium text-gray-700 sm:col-span-6">{{ __('Materialets mått') }}</p>
    <p class="-mt-2 text-sm text-gray-500 sm:col-span-6">{{ __('För trä och skivor. Med måtten räknar NONOX fram omräkningarna mellan löpmeter, kvadrat, kubik och styck. Mått i millimeter, till exempel 45 eller 0,6.') }}</p>
    <x-ui.field for="article_material_length" :label="__('Längd (mm)')" error="material_length" class="sm:col-span-2">
        <input id="article_material_length" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.blur="form.material_length">
    </x-ui.field>
    <x-ui.field for="article_material_width" :label="__('Bredd (mm)')" error="material_width" class="sm:col-span-2">
        <input id="article_material_width" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.blur="form.material_width">
    </x-ui.field>
    <x-ui.field for="article_material_thickness" :label="__('Tjocklek (mm)')" error="material_thickness" class="sm:col-span-2">
        <input id="article_material_thickness" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.blur="form.material_thickness">
    </x-ui.field>

    @if($derived->isNotEmpty())
        <ul class="space-y-1 text-sm text-gray-700 sm:col-span-6">
            @foreach($derived as $row)
                <li wire:key="derived-{{ $row->id }}">{{ __('1 :stock = :factor :unit', ['stock' => $stockCode, 'factor' => App\Domain\Units\UnitFractions::format($row->fraction()), 'unit' => $row->unit]) }} <span class="text-xs text-gray-500">({{ __('från måtten') }})</span></li>
            @endforeach
        </ul>
    @endif

    <p class="text-sm font-medium text-gray-700 sm:col-span-6">{{ __('Omräkningar') }}</p>
    <p class="-mt-2 text-sm text-gray-500 sm:col-span-6">{{ __('När en enhet inte räknas om av sig själv, till exempel förpackning till styck: raden läses som en mening, till exempel 1 st = 0,005 förp. Enheter av samma slag, som meter och millimeter, behöver ingen omräkning.') }}</p>
    @foreach(($form['conversions'] ?? []) as $index => $conversion)
        <div class="grid gap-2 sm:col-span-6 sm:grid-cols-6" wire:key="conversion-row-{{ $index }}">
            <x-ui.field :for="'conversion_per_'.$index" :label="__('1 :unit =', ['unit' => $stockCode])" :error="'conversions.'.$index.'.per'" class="sm:col-span-2">
                <input id="conversion_per_{{ $index }}" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.blur="form.conversions.{{ $index }}.per" placeholder="0,005">
            </x-ui.field>
            <x-ui.field :for="'conversion_unit_'.$index" :label="__('Enhet')" :error="'conversions.'.$index.'.unit'" class="sm:col-span-2">
                <select id="conversion_unit_{{ $index }}" class="{{ $input }} mt-1" wire:model.blur="form.conversions.{{ $index }}.unit">
                    <option value="">{{ __('Välj enhet') }}</option>
                    @foreach($units as $unit)
                        <option value="{{ $unit->code }}">{{ $unit->code }} ({{ $unit->name }})</option>
                    @endforeach
                </select>
            </x-ui.field>
            <div class="flex items-end"><button type="button" class="pb-2 text-sm text-gray-700 underline" wire:click="removeConversion({{ $index }})">{{ __('Ta bort') }}</button></div>
        </div>
    @endforeach
    <div class="sm:col-span-6"><button type="button" class="text-sm font-semibold text-accent-700 underline" wire:click="addConversion">{{ __('Lägg till omräkning') }}</button></div>
</fieldset>
