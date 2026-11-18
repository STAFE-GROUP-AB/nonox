@php
    use App\Modules\Inventory\Domain\Quantity;
    use App\Support\Money\Money;

    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $kr = fn (int $ore): string => Money::ofOre($ore)->format();
    $qty = fn (int $milli): string => Quantity::format($milli);
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

        <section data-match-rows class="mt-6 border-t border-stone-100 pt-4" aria-labelledby="match-rows-title">
            <h3 id="match-rows-title" class="text-base font-semibold text-ink">{{ __('Avstämning per rad') }}</h3>
            <p class="mt-1 text-sm text-gray-600">
                {{ __('Toleranser: :tolerances.', ['tolerances' => $tolerances->describe()]) }}
                {{ $tolerances->blocksAttest
                    ? __('En avvikelse utanför toleranserna stoppar attesten tills någon med rätt att attestera har godkänt den med ett skäl.')
                    : __('En avvikelse utanför toleranserna visas vid attesten men stoppar den inte.') }}
            </p>

            @if($results->isEmpty())
                <p class="mt-3 text-sm text-gray-600">{{ $registered ? __('Ingen avstämning ännu. Fördela fakturans rader på orderraderna nedan.') : __('Raderna stäms av när fakturan är registrerad.') }}</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-gray-600">
                            <tr>
                                <th scope="col" class="py-2 pr-3 font-medium">{{ __('Orderrad') }}</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Beställt') }}</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Mottaget') }}</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Fakturerat') }}</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Avvikelse') }}</th>
                                <th scope="col" class="py-2 font-medium">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($results as $result)
                                @php($orderRow = $result->purchase_order_row_id === null ? null : $orderRows->get($result->purchase_order_row_id))
                                <tr wire:key="match-result-{{ $result->id }}" data-match-result="{{ $result->status->value }}" class="align-top">
                                    <td class="py-2 pr-3">
                                        @if($orderRow)
                                            <span class="block font-medium">{{ $orderRow->description }}</span>
                                            <span class="block text-gray-600">{{ $orderRow->purchaseOrder->title() }} · {{ $orderRow->unit?->code }}</span>
                                        @else
                                            <span class="block font-medium">{{ $invoiceRows->get($result->supplier_invoice_row_id)?->description ?: __('Fakturarad utan orderrad') }}</span>
                                            <span class="block text-gray-600">{{ __('Ingen orderrad') }}</span>
                                        @endif
                                        <ul class="mt-1 space-y-0.5 text-gray-700">
                                            @foreach($result->reasonCases() as $reason)
                                                <li>{{ $reason->label() }}</li>
                                            @endforeach
                                        </ul>
                                        @if($result->override_reason !== null)
                                            <p class="mt-1 text-gray-700">{{ __('Godkänd av :name: :reason', ['name' => $overriders[$result->overridden_by] ?? __('tidigare medlem'), 'reason' => $result->override_reason]) }}</p>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        @if($orderRow)
                                            <span class="block">{{ $qty($result->ordered_milli) }}</span>
                                            <span class="block text-gray-600">{{ $kr($result->ordered_ore) }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        @if($orderRow)
                                            <span class="block">{{ $qty($result->received_milli) }}</span>
                                            <span class="block text-gray-600">{{ $kr($result->received_ore) }}</span>
                                            @if($result->previously_invoiced_milli > 0)
                                                <span class="block text-gray-600">{{ __('Fakturerat tidigare: :quantity', ['quantity' => $qty($result->previously_invoiced_milli)]) }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        @if($orderRow)
                                            <span class="block">{{ $qty($result->invoiced_milli) }}</span>
                                        @endif
                                        <span class="block text-gray-600">{{ $kr($result->invoiced_ore) }}</span>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">
                                        @if($orderRow)
                                            <span @class(['block', 'text-red-700' => $result->quantity_diff_milli > 0])>{{ $result->quantity_diff_milli > 0 ? '+' : '' }}{{ $qty($result->quantity_diff_milli) }}</span>
                                        @endif
                                        <span @class(['block', 'text-red-700' => $result->price_diff_ore > 0])>{{ $result->price_diff_ore > 0 ? '+' : '' }}{{ $kr($result->price_diff_ore) }}</span>
                                    </td>
                                    <td class="py-2">
                                        <x-ui.badge :tone="$result->status->tone()">{{ $result->status->label() }}</x-ui.badge>
                                        @if($canOverride && $result->isOpen())
                                            @if($overrideResultId === $result->id)
                                                <form wire:submit="override" class="mt-2 flex min-w-56 flex-col gap-2">
                                                    <label for="override-reason" class="text-sm font-medium text-gray-700">{{ __('Skäl till att avvikelsen godkänns') }}</label>
                                                    <textarea id="override-reason" rows="2" maxlength="500" wire:model="overrideReason" class="block w-full rounded-lg border-gray-300 shadow-xs focus:border-accent-500 focus:ring-accent-500" aria-describedby="override-reason-hint"></textarea>
                                                    <span id="override-reason-hint" class="text-xs text-gray-600">{{ __('Skriv vad som gör att raden kan betalas, inte uppgifter om personer.') }}</span>
                                                    <x-input-error for="overrideReason" />
                                                    <span class="flex items-center gap-3">
                                                        <x-secondary-button type="submit">{{ __('Godkänn avvikelsen') }}</x-secondary-button>
                                                        <button type="button" wire:click="cancelOverride" class="text-sm text-gray-600 underline">{{ __('Avbryt') }}</button>
                                                    </span>
                                                </form>
                                            @else
                                                <button type="button" wire:click="startOverride({{ $result->id }})" class="mt-2 block text-sm text-accent-700 underline">{{ __('Godkänn avvikelsen') }}</button>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($registered && $canManage && $invoiceRows->isNotEmpty())
                <form wire:submit="saveAllocations" data-match-allocations class="mt-5 space-y-3">
                    <h4 class="text-sm font-semibold text-ink">{{ __('Fördela fakturans rader') }}</h4>
                    <p class="text-sm text-gray-600">{{ __('Välj vilken orderrad varje fakturarad betalar och hur många, i orderradens enhet. Frakt och öresavrundning kan stå utan orderrad.') }}</p>
                    <x-input-error for="allocations" />
                    @foreach($invoiceRows as $invoiceRow)
                        <div class="grid gap-3 rounded-xl border border-stone-100 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem]" wire:key="match-allocation-{{ $invoiceRow->id }}">
                            <div class="text-sm">
                                <span class="block font-medium">{{ $invoiceRow->description ?: __('Fakturarad') }}</span>
                                <span class="block text-gray-600">{{ $invoiceRow->account?->number }} · <span class="tabular-nums">{{ $kr($invoiceRow->amount_ore->ore) }}</span></span>
                            </div>
                            <x-ui.field :for="'match-row-'.$invoiceRow->id" :label="__('Orderrad')" :error="'allocations.'.$invoiceRow->id.'.order_row'">
                                <select id="match-row-{{ $invoiceRow->id }}" wire:model="allocations.{{ $invoiceRow->id }}.order_row" class="{{ $input }}">
                                    <option value="">{{ __('Ingen orderrad') }}</option>
                                    @foreach($orderRows as $orderRow)
                                        <option value="{{ $orderRow->id }}">{{ $orderRow->purchaseOrder->title() }} · {{ $orderRow->description }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field :for="'match-quantity-'.$invoiceRow->id" :label="__('Antal')" :error="'allocations.'.$invoiceRow->id.'.quantity'">
                                <input id="match-quantity-{{ $invoiceRow->id }}" wire:model="allocations.{{ $invoiceRow->id }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums" placeholder="{{ __('Mottaget') }}">
                            </x-ui.field>
                        </div>
                    @endforeach
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara fördelningen') }}</x-button>
                        <x-secondary-button type="button" wire:click="suggest" wire:loading.attr="disabled">{{ __('Föreslå efter belopp') }}</x-secondary-button>
                    </div>
                </form>
            @endif
        </section>
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
