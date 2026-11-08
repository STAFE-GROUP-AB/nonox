@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
    <x-ui.field for="inventory-method" :label="__('Värderingsmetod')" error="settings.valuation_method" :hint="__('Hur anskaffningsvärdet för varor i lager räknas vid bokslutet. Sist in, först ut är inte tillåtet.')" class="sm:col-span-2">
        <select id="inventory-method" wire:model="settings.valuation_method" class="{{ $input }}">
            @foreach($methods as $method)
                <option value="{{ $method->value }}">{{ $method->label() }}</option>
            @endforeach
        </select>
    </x-ui.field>
    <label class="flex items-start gap-3 sm:col-span-2">
        <input type="checkbox" wire:model="settings.allow_negative_stock" class="mt-1 rounded-sm border-stone-300 text-ink focus:ring-accent-500">
        <span>
            <span class="block font-medium text-ink">{{ __('Tillåt negativt lagersaldo') }}</span>
            <span class="block text-sm text-gray-600">{{ __('Låter dig plocka och sälja fler än lagret visar, till exempel när en inleverans inte är registrerad än. Saldot blir då under noll tills varorna tas emot.') }}</span>
        </span>
    </label>
    <x-ui.field for="inventory-default-class" :label="__('Lagerslag för artiklar utan eget')" error="settings.default_stock_class" class="sm:col-span-2" :hint="__('Varor du köper in och säljer vidare är handelsvaror. Det du lagar mat av eller tillverkar av är råvaror. Lagerslaget kan också väljas per artikel.')">
        <select id="inventory-default-class" wire:model="settings.default_stock_class" class="{{ $input }}">
            @foreach($classes as $class)
                <option value="{{ $class->value }}">{{ $class->label() }}</option>
            @endforeach
        </select>
    </x-ui.field>
    <x-ui.field for="inventory-stock-account" :label="__('Lagerkonto för handelsvaror')" error="settings.stock_account" :hint="__('1460 Lager av handelsvaror.')">
        <input id="inventory-stock-account" type="number" min="1400" max="1499" wire:model="settings.stock_account" class="{{ $input }} w-28 tabular-nums">
    </x-ui.field>
    <x-ui.field for="inventory-change-account" :label="__('Lagerförändring för handelsvaror')" error="settings.change_account" :hint="__('4960 Förändring av lager av handelsvaror.')">
        <input id="inventory-change-account" type="number" min="4900" max="4999" wire:model="settings.change_account" class="{{ $input }} w-28 tabular-nums">
    </x-ui.field>
    <x-ui.field for="inventory-raw-stock-account" :label="__('Lagerkonto för råvaror')" error="settings.raw_material_stock_account" :hint="__('1410 Lager av råvaror.')">
        <input id="inventory-raw-stock-account" type="number" min="1400" max="1499" wire:model="settings.raw_material_stock_account" class="{{ $input }} w-28 tabular-nums">
    </x-ui.field>
    <x-ui.field for="inventory-raw-change-account" :label="__('Lagerförändring för råvaror')" error="settings.raw_material_change_account" :hint="__('4910 Förändring av lager av råvaror.')">
        <input id="inventory-raw-change-account" type="number" min="4900" max="4999" wire:model="settings.raw_material_change_account" class="{{ $input }} w-28 tabular-nums">
    </x-ui.field>
    @if($k2)
        <label class="flex items-start gap-3 sm:col-span-2">
            <input type="checkbox" wire:model="settings.rule_97" class="mt-1 rounded-sm border-stone-300 text-ink focus:ring-accent-500">
            <span>
                <span class="block font-medium text-ink">{{ __('Ta upp lagret till 97 procent av anskaffningsvärdet (K2 punkt 12.5)') }}</span>
                <span class="block text-sm text-gray-600">{{ __('Gäller bara när ingen vara är nedskriven till nettoförsäljningsvärde. Varor som ska kasseras måste ha flyttats bort från lagret för att slängas. Ligger de kvar i lagret, värderade till noll, räknas det som en inkuransbedömning och regeln får inte användas för något i lagret (K2 punkt 12.18).') }}</span>
            </span>
        </label>
    @endif
    <div class="sm:col-span-2">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
    </div>
</form>
