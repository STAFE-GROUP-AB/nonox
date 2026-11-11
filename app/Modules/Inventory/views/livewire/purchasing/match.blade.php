@php
    use App\Support\Money\Money;

    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $kr = fn (int $ore): string => Money::ofOre($ore)->format();
@endphp
<x-ui.card data-purchase-order-match :title="__('Inköpsorder')" :description="__('Jämför fakturan med det som beställdes och det som har tagits emot. NONOX markerar skillnader men attesterar ingenting; du avgör.')">
    @if($match->orders->isEmpty())
        <p class="text-sm text-gray-600">{{ __('Fakturan är inte kopplad till någon inköpsorder.') }}</p>
    @else
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-gray-600">{{ __('Beställt') }}</dt><dd class="font-semibold tabular-nums">{{ $kr($match->orderedOre) }}</dd></div>
            <div><dt class="text-gray-600">{{ __('Mottaget') }}</dt><dd class="font-semibold tabular-nums">{{ $kr($match->receivedOre) }}</dd></div>
            <div><dt class="text-gray-600">{{ __('Fakturerat exkl. moms') }}</dt><dd class="font-semibold tabular-nums">{{ $kr($match->invoiceNetOre) }}</dd></div>
        </dl>

        @if($match->flags === [])
            <p class="mt-4 rounded-xl border border-green-200 bg-green-50 p-3 text-sm text-green-900">{{ __('Fakturan stämmer med det som har tagits emot.') }}</p>
        @else
            <ul class="mt-4 list-disc space-y-1 rounded-xl border border-amber-300 bg-amber-50 p-3 pl-8 text-sm text-amber-900">
                @foreach($match->flags as $flag)
                    <li>{{ $flag }}</li>
                @endforeach
            </ul>
        @endif

        <ul class="mt-4 divide-y divide-stone-100 text-sm">
            @foreach($match->orders as $order)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="match-order-{{ $order->id }}">
                    <a href="{{ route('inventory.purchaseOrders.show', $order->id) }}" class="font-semibold text-accent-700 underline">{{ $order->title() }}</a>
                    <span class="flex items-center gap-3">
                        <x-ui.badge :tone="$order->status->tone()">{{ $order->status->label() }}</x-ui.badge>
                        @if($canManage)
                            <button type="button" wire:click="unlink({{ $order->id }})" class="text-gray-700 underline">{{ __('Ta bort kopplingen') }}</button>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @if($canManage && $match->candidates->isNotEmpty())
        <form wire:submit="link" class="mt-4 flex flex-wrap items-end gap-3">
            <x-ui.field for="match-order" :label="__('Koppla till inköpsorder')" error="purchase_order_id" class="min-w-56 flex-1">
                <select id="match-order" wire:model="orderId" class="{{ $input }}">
                    <option value="">{{ __('Välj inköpsorder') }}</option>
                    @foreach($match->candidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ $candidate->title() }} · {{ $candidate->order_date->toDateString() }} · {{ $candidate->status->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-secondary-button type="submit">{{ __('Koppla') }}</x-secondary-button>
        </form>
    @endif
</x-ui.card>
