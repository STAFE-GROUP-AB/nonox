@php($input = 'mt-1 block w-full max-w-xs border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-right tabular-nums')
<form wire:submit="save" class="space-y-5">
    <x-ui.field for="manufacturing-overhead" :label="__('Omkostnadspålägg %')" error="overhead" :hint="__('Läggs på material och arbete i kalkylen, för lokaler, maskiner och administration. Tomt betyder inget pålägg.')">
        <input id="manufacturing-overhead" wire:model="overhead" inputmode="decimal" class="{{ $input }}" placeholder="0">
    </x-ui.field>
    <label class="flex items-start gap-3">
        <input type="checkbox" wire:model="overheadInStock" class="mt-1 rounded-sm border-stone-300 text-ink focus:ring-accent-500">
        <span class="text-sm text-gray-700">
            {{ __('Räkna in pålägget i lagervärdet för tillverkade produkter och produkter i arbete') }}
            <span class="block text-xs text-gray-500">{{ __('Av som standard: då är lagervärdet material och direkt arbete. Kontrollera med din redovisningskonsult vad ditt regelverk (K2 eller K3) tillåter innan du slår på det.') }}</span>
        </span>
    </label>
    <fieldset class="space-y-3">
        <legend class="text-sm font-semibold text-ink">{{ __('Produkter i arbete vid bokslutet') }}</legend>
        <p class="text-sm text-gray-600">{{ __('Komponenter och arbete på tillverkningsorder som inte är färdiga bokförs på ett eget lagerkonto när lagret värderas vid bokslutet.') }}</p>
        <x-ui.field for="manufacturing-wip-stock" :label="__('Lagerkonto')" error="wipStockAccount">
            <input id="manufacturing-wip-stock" wire:model="wipStockAccount" inputmode="numeric" maxlength="4" class="{{ $input }}">
        </x-ui.field>
        <x-ui.field for="manufacturing-wip-change" :label="__('Konto för förändringen')" error="wipChangeAccount">
            <input id="manufacturing-wip-change" wire:model="wipChangeAccount" inputmode="numeric" maxlength="4" class="{{ $input }}">
        </x-ui.field>
    </fieldset>
    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
</form>
