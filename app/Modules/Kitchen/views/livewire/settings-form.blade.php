@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
    <x-ui.field for="kitchen-location" :label="__('Kökets lagerställe')" error="settings.location_id" class="sm:col-span-2" :hint="__('Svinn och personalmat dras härifrån om inget annat väljs.')">
        <select id="kitchen-location" wire:model="settings.location_id" class="{{ $input }}">
            <option value="">{{ __('Inget valt') }}</option>
            @foreach($locations as $location)
                <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
            @endforeach
        </select>
    </x-ui.field>
    <x-ui.field for="kitchen-target" :label="__('Mål för råvarukostnad i procent')" error="settings.target_food_cost" :hint="__('Valfritt. Rätter över målet markeras på menyn. NONOX ändrar aldrig ett pris.')">
        <input id="kitchen-target" wire:model="settings.target_food_cost" inputmode="decimal" class="{{ $input }} w-28 text-right tabular-nums" placeholder="30">
    </x-ui.field>
    <x-ui.field for="kitchen-average" :label="__('Snittkostnad för valfri rätt')" error="settings.average_meal_cost" :hint="__('Råvarukostnaden för en personalmåltid utan vald rätt, i kronor.')">
        <input id="kitchen-average" wire:model="settings.average_meal_cost" inputmode="decimal" class="{{ $input }} w-32 text-right tabular-nums" placeholder="45,00">
    </x-ui.field>
    <label class="flex items-start gap-3 sm:col-span-2">
        <input type="checkbox" wire:model="settings.draw_on_sale" @disabled(! $canDraw) class="mt-1 rounded-sm border-stone-300 text-ink focus:ring-accent-500">
        <span>
            <span class="block font-medium text-ink">{{ __('Dra råvarorna från lagret när kassan säljer rätterna') }}</span>
            <span class="block text-sm text-gray-600">{{ __('Varje natt dras ingredienserna i gårdagens försäljning från kassans lagerställe enligt recepten. Gäller försäljning från och med dagen inställningen slås på. Kräver bra recept; utan den följer inventeringen förbrukningen och avvikelserapporten fungerar ändå.') }}@if(! $canDraw) {{ __('Bara den som får ändra lagrets inställningar kan slå på det.') }}@endif</span>
        </span>
    </label>
    <div class="sm:col-span-2">
        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
    </div>
</form>
