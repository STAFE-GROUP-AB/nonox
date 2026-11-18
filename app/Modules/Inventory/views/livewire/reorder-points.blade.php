@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
    $unit = $article->stockUnit?->code ?? $article->unit;
@endphp
<div class="space-y-3" id="reorder-points-panel">
    <p class="text-sm font-medium text-ink">{{ __('Beställningspunkt per lager') }}</p>
    <p class="text-sm text-gray-600">{{ __('Sätts en punkt för ett lager bedöms artikeln lager för lager i inköpsförslaget, och beställningspunkten ovan används inte längre. Det som är på väg till lagret räknas med.') }}</p>
    @if($settings->isNotEmpty())
        <ul class="divide-y divide-stone-200 text-sm">
            @foreach($settings as $setting)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="reorder-setting-{{ $setting->id }}">
                    <span>
                        <span class="font-semibold">{{ $setting->warehouse?->full_code }} {{ $setting->warehouse?->name }}</span>
                        · {{ __('Beställningspunkt :point :unit', ['point' => $q($setting->reorder_point_milli), 'unit' => $unit]) }}@if($setting->reorder_quantity_milli !== null), {{ __('beställ :quantity', ['quantity' => $q($setting->reorder_quantity_milli)]) }}@endif
                    </span>
                    <span class="flex gap-3">
                        <button type="button" wire:click="edit({{ $setting->id }})" class="font-semibold text-accent-700 underline">{{ __('Ändra') }}</button>
                        <button type="button" wire:click="remove({{ $setting->id }})" wire:confirm="{{ __('Ta bort beställningspunkten för lagret?') }}" class="font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
    @if($article->is_stock_item)
        <div class="flex flex-wrap items-end gap-3">
            <x-ui.field for="reorder-warehouse-{{ $article->id }}" :label="__('Lager')" error="form.warehouse_id">
                <select id="reorder-warehouse-{{ $article->id }}" wire:model="form.warehouse_id" class="{{ $input }} w-48">
                    <option value="">{{ __('Välj lager') }}</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->full_code }} {{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="reorder-point-{{ $article->id }}" :label="__('Beställningspunkt')" error="form.point">
                <input id="reorder-point-{{ $article->id }}" wire:model="form.point" inputmode="decimal" class="{{ $input }} w-28 text-right tabular-nums">
            </x-ui.field>
            <x-ui.field for="reorder-quantity-{{ $article->id }}" :label="__('Beställ antal')" error="form.quantity">
                <input id="reorder-quantity-{{ $article->id }}" wire:model="form.quantity" inputmode="decimal" class="{{ $input }} w-28 text-right tabular-nums">
            </x-ui.field>
            <x-secondary-button type="button" wire:click="save">{{ __('Spara för lagret') }}</x-secondary-button>
        </div>
    @endif
</div>
