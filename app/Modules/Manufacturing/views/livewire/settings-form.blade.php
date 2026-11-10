<form wire:submit="save" class="space-y-5">
    <x-ui.field for="manufacturing-overhead" :label="__('Omkostnadspålägg %')" error="overhead" :hint="__('Läggs på material och arbete i kalkylen, för lokaler, maskiner och administration. Tomt betyder inget pålägg. Gäller bara kalkylen, inte lagervärdet.')">
        <input id="manufacturing-overhead" wire:model="overhead" inputmode="decimal" class="mt-1 block w-full max-w-xs border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-right tabular-nums" placeholder="0">
    </x-ui.field>
    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
</form>
