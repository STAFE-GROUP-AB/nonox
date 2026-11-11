@php
    use App\Modules\Inventory\Domain\Quantity;

    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp
<div class="space-y-6">
    <x-ui.card>
        @if($canManage)
            <x-slot:cta>
                <a href="{{ route('manufacturing.productionOrders.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-accent-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500 sm:min-h-0">{{ __('Ny tillverkningsorder') }}</a>
            </x-slot:cta>
        @endif

        <div class="mb-4 grid max-w-2xl gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    @foreach($filters as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Sök produkt eller nummer') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}">
            </label>
        </div>

        @if($orders->isEmpty())
            <x-ui.empty-state :title="__('Inga tillverkningsorder')">
                {{ __('Skapa en tillverkningsorder för en produkt med stycklista, mot lager eller från en rad på en kundorder. Ordern får en egen kopia av stycklistan.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-600">
                            <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Produkt') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Planerad start') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Klart senast') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Kundorder') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($orders as $order)
                            <tr wire:key="to-{{ $order->id }}">
                                <td class="py-2 pr-4 tabular-nums"><a href="{{ route('manufacturing.productionOrders.show', $order->id) }}" class="font-semibold text-accent-700 underline">{{ $order->reference() }}</a></td>
                                <td class="py-2 pr-4">{{ $order->article?->name }} <span class="block text-xs text-gray-500">{{ $order->article?->number }}</span></td>
                                <td class="py-2 pr-4 text-right tabular-nums whitespace-nowrap">{{ Quantity::format($order->quantity_milli) }} {{ $order->article?->stockUnitCode() }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :tone="$order->status->tone()">{{ $order->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4 tabular-nums">{{ $order->planned_start_on?->toDateString() ?? '–' }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $order->due_on?->toDateString() ?? '–' }}</td>
                                <td class="py-2 pr-4">
                                    @if($order->salesDocument)
                                        <a href="{{ route('invoicing.orders.show', ['document' => $order->salesDocument->id]) }}" class="text-accent-700 underline">{{ $order->salesDocument->title() }}</a>
                                        <span class="block text-xs text-gray-500">{{ $order->salesDocument->customer?->name }}</span>
                                    @else
                                        <span class="text-gray-500">{{ __('Mot lager') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $orders->links() }}</div>
        @endif
    </x-ui.card>
</div>
