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
    <x-ui.field for="inventory-stock-account" :label="__('Lagerkonto')" error="settings.stock_account" :hint="__('1460 Lager av handelsvaror för varor du köper in och säljer, 1410 Lager av råvaror för det du tillverkar av.')">
        <input id="inventory-stock-account" type="number" min="1400" max="1499" wire:model="settings.stock_account" class="{{ $input }} w-28 tabular-nums">
    </x-ui.field>
    <x-ui.field for="inventory-change-account" :label="__('Konto för lagerförändring')" error="settings.change_account" :hint="__('4960 för handelsvaror, 4910 för råvaror. Lagerförändringen bokförs mot det här kontot vid bokslutet.')">
        <input id="inventory-change-account" type="number" min="4900" max="4999" wire:model="settings.change_account" class="{{ $input }} w-28 tabular-nums">
    </x-ui.field>
    <div class="sm:col-span-2">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
    </div>
</form>
