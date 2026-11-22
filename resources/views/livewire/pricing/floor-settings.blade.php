@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div data-floor-settings>
<x-ui.card :title="__('Lägsta täckningsgrad')" :description="__('Av som standard. Slå på den om priser som skrivs för hand inte ska hamna för nära inköpspriset.')">
    <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
        <x-ui.field for="floor_percent" :label="__('Lägsta täckningsgrad (%)')" error="percent" class="sm:col-span-2" :hint="__('Täckningsgraden är vinsten delad med försäljningspriset. Lämna tomt för att stänga av.')">
            <input id="floor_percent" type="text" inputmode="decimal" class="{{ $input }} mt-1 tabular-nums" wire:model="percent" maxlength="5" autocomplete="off" placeholder="15" @disabled(! $canManage)>
        </x-ui.field>
        <x-ui.field for="floor_policy" :label="__('Under täckningsgraden')" error="policy" class="sm:col-span-4" :hint="__('Kräv godkännande gäller priser och rabatter som skrivs för hand på fakturor, offerter och ordrar. Priser från prislistor och prisavtal varnar bara.')">
            <select id="floor_policy" class="{{ $input }} mt-1" wire:model="policy" @disabled(! $canManage)>
                <option value="WARN">{{ __('Varna bara') }}</option>
                <option value="REQUIRE_APPROVAL">{{ __('Kräv godkännande innan dokumentet skickas') }}</option>
            </select>
        </x-ui.field>
        @if($separate)
            <p class="text-sm text-gray-600 sm:col-span-6">{{ __('Den som har skrivit dokumentet, ändrat raderna eller begärt godkännandet kan inte godkänna det själv. Det följer inställningen om att någon annan ska attestera.') }}</p>
        @else
            <p class="text-sm text-gray-600 sm:col-span-6">{{ __('Företaget tillåter att samma person skriver och attesterar, så den som har behörigheten kan godkänna sina egna priser.') }}</p>
        @endif
        @if($canManage)
            <div class="sm:col-span-6">
                <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
            </div>
        @else
            <p class="text-sm text-gray-600 sm:col-span-6">{{ __('Du kan se inställningen men inte ändra den. Det kräver behörigheten att hantera prislistor.') }}</p>
        @endif
    </form>
</x-ui.card>
</div>
