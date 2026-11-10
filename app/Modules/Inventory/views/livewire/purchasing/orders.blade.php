@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6">
    <x-ui.card>
        @if($canManage)
            <x-slot:cta>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('inventory.purchaseProposals') }}" class="inline-flex items-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-stone-50">{{ __('Inköpsförslag') }}</a>
                    <a href="{{ route('inventory.purchaseOrders.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Ny inköpsorder') }}</a>
                </div>
            </x-slot:cta>
        @endif

        <div class="mb-4 grid max-w-2xl gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="oppna">{{ __('Öppna') }}</option>
                    <option value="utkast">{{ __('Utkast') }}</option>
                    <option value="vantar">{{ __('Väntar på varor') }}</option>
                    <option value="alla">{{ __('Alla') }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Leverantör') }}</span>
                <select wire:model.live="supplier" class="{{ $input }}">
                    <option value="">{{ __('Alla leverantörer') }}</option>
                    @foreach($suppliers as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if($orders->isEmpty())
            <x-ui.empty-state :title="__('Inga inköpsorder')">{{ __('Skriv en inköpsorder till en leverantör, eller låt inköpsförslaget föreslå vad som behöver beställas utifrån beställningspunkter och öppna kundordrar.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-600">
                            <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Leverantör') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Orderdatum') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Önskat leveransdatum') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp exkl. moms') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($orders as $order)
                            <tr wire:key="po-{{ $order->id }}">
                                <td class="py-2 pr-4 tabular-nums"><a href="{{ route('inventory.purchaseOrders.show', $order->id) }}" class="font-semibold text-accent-700 underline">{{ $order->number }}</a>@if($order->version > 1) <span class="text-gray-500">{{ __('v. :version', ['version' => $order->version]) }}</span>@endif</td>
                                <td class="py-2 pr-4">{{ $order->supplier?->name }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :tone="$order->status->tone()">{{ $order->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4 tabular-nums">{{ $order->order_date->toDateString() }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $order->requested_delivery_on?->toDateString() }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ App\Support\Money\Money::ofOre($order->totalOre())->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $orders->links() }}</div>
        @endif
    </x-ui.card>
</div>
