@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
@endphp
<div class="space-y-6">
    @if($printing !== null)
        <livewire:inventory.label-print kind="ARTICLE" :items="$printing" :given-label="__('Mottaget antal')" :key="'label-print-'.md5((string) json_encode($printing))" />
    @endif

    @if($creating)
        <x-ui.card :title="__('Ny inleverans')" :description="__('Det du väntar på från leverantören. Inget bokförs; lagret ökar när du tar emot varorna.')">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-6">
                    <x-ui.field for="receipt-supplier" :label="__('Leverantör')" error="form.supplier_id" class="sm:col-span-2">
                        <select id="receipt-supplier" wire:model.live="form.supplier_id" class="{{ $input }}">
                            <option value="">{{ __('Ingen leverantör') }}</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="receipt-invoice" :label="__('Leverantörsfaktura')" error="form.supplier_invoice_id" class="sm:col-span-2" :hint="__('Valfritt. Kopplar inleveransen till fakturan.')">
                        <select id="receipt-invoice" wire:model="form.supplier_invoice_id" class="{{ $input }}">
                            <option value="">{{ __('Ingen faktura') }}</option>
                            @foreach($invoices as $invoice)
                                <option value="{{ $invoice->id }}">{{ $invoice->invoice_number }} ({{ $invoice->invoice_date->toDateString() }})</option>
                            @endforeach
                            @if(($form['supplier_invoice_id'] ?? null) && ! $invoices->contains('id', $form['supplier_invoice_id']))
                                <option value="{{ $form['supplier_invoice_id'] }}">{{ $form['reference'] ?: __('Vald faktura') }}</option>
                            @endif
                        </select>
                    </x-ui.field>
                    <x-ui.field for="receipt-expected" :label="__('Väntas')" error="form.expected_on" class="sm:col-span-2">
                        <input id="receipt-expected" type="date" wire:model="form.expected_on" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="receipt-reference" :label="__('Referens')" error="form.reference" class="sm:col-span-2" :hint="__('Till exempel följesedelns nummer.')">
                        <input id="receipt-reference" wire:model="form.reference" maxlength="255" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="receipt-note" :label="__('Anteckning')" error="form.note" class="sm:col-span-4">
                        <input id="receipt-note" wire:model="form.note" maxlength="255" class="{{ $input }}">
                    </x-ui.field>
                </div>

                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-ink">{{ __('Rader') }}</legend>
                    @foreach($form['lines'] as $index => $line)
                        <div class="grid gap-3 rounded-2xl bg-stone-50 p-3 sm:grid-cols-12" wire:key="receipt-line-{{ $index }}">
                            <x-ui.field for="line-article-{{ $index }}" :label="__('Artikel')" error="form.lines.{{ $index }}.article_id" class="sm:col-span-4">
                                <select id="line-article-{{ $index }}" wire:model="form.lines.{{ $index }}.article_id" class="{{ $input }}">
                                    <option value="">{{ __('Välj artikel') }}</option>
                                    @foreach($articles as $article)
                                        <option value="{{ $article->id }}">{{ $article->number }} {{ $article->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            <x-ui.field for="line-quantity-{{ $index }}" :label="__('Antal')" error="form.lines.{{ $index }}.quantity" class="sm:col-span-2">
                                <input id="line-quantity-{{ $index }}" wire:model="form.lines.{{ $index }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                            </x-ui.field>
                            <x-ui.field for="line-location-{{ $index }}" :label="__('Plats')" error="form.lines.{{ $index }}.location_id" class="sm:col-span-3" :hint="__('Tomt: artikelns standardplats.')">
                                <select id="line-location-{{ $index }}" wire:model="form.lines.{{ $index }}.location_id" class="{{ $input }}">
                                    <option value="">{{ __('Standardplats') }}</option>
                                    @foreach($locations as $location)
                                        <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                            @if($seesPrices)
                                <x-ui.field for="line-cost-{{ $index }}" :label="__('Inköpspris/st')" error="form.lines.{{ $index }}.unit_cost" class="sm:col-span-2" :hint="__('Tomt: artikelns inköpspris.')">
                                    <input id="line-cost-{{ $index }}" wire:model="form.lines.{{ $index }}.unit_cost" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                </x-ui.field>
                            @endif
                            <div class="flex items-end sm:col-span-1">
                                @if(count($form['lines']) > 1)
                                    <button type="button" wire:click="removeLine({{ $index }})" class="min-h-11 text-sm font-medium text-gray-700 underline">{{ __('Ta bort') }}</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    <button type="button" wire:click="addLine" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till rad') }}</button>
                </fieldset>

                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Skapa inleverans') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($receivingReceipt)
        <x-ui.card :title="__('Ta emot inleverans :number', ['number' => $receivingReceipt->number])" :description="__('Ange hur mycket som kom och var det ska ligga. Det som inte kom kan tas emot senare.')">
            <form wire:submit="receiveGoods" class="space-y-4">
                <ul class="divide-y divide-stone-200">
                    @foreach($receivingReceipt->lines as $line)
                        <li class="grid gap-3 py-3 sm:grid-cols-12 sm:items-end" wire:key="receive-{{ $line->id }}">
                            <div class="sm:col-span-5">
                                <span class="font-semibold tabular-nums">{{ $line->article?->number }}</span> {{ $line->article?->name }}
                                <span class="block text-sm text-gray-500">{{ __('Beställt :ordered, mottaget :received', ['ordered' => $q($line->ordered_milli), 'received' => $q($line->received_milli)]) }} {{ $line->article?->stockUnitCode() }}</span>
                            </div>
                            <x-ui.field for="receive-quantity-{{ $line->id }}" :label="__('Kom nu')" error="receive.{{ $line->id }}.quantity" class="sm:col-span-2">
                                <input id="receive-quantity-{{ $line->id }}" wire:model="receive.{{ $line->id }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                            </x-ui.field>
                            <x-ui.field for="receive-location-{{ $line->id }}" :label="__('Plats')" error="receive.{{ $line->id }}.location_id" class="sm:col-span-5">
                                <select id="receive-location-{{ $line->id }}" wire:model="receive.{{ $line->id }}.location_id" class="{{ $input }}">
                                    <option value="">{{ __('Välj plats') }}</option>
                                    @foreach($locations as $location)
                                        <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        </li>
                    @endforeach
                </ul>
                <x-ui.field for="receive-date" :label="__('Mottaget den')" error="date" class="max-w-xs">
                    <input id="receive-date" type="date" wire:model="receiveDate" class="{{ $input }}" required>
                </x-ui.field>
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Ta emot') }}</x-button>
                    <x-secondary-button type="button" wire:click="cancel">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canCount && ! $creating)
            <x-slot:cta>
                <x-button type="button" wire:click="create">{{ __('Ny inleverans') }}</x-button>
            </x-slot:cta>
        @endif

        <div class="mb-4 flex flex-wrap items-end gap-6">
            <label class="block w-full max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="oppna">{{ __('Väntar eller delvis mottagna') }}</option>
                    <option value="alla">{{ __('Alla') }}</option>
                </select>
            </label>
            @if($awaiting->isNotEmpty())
                <form wire:submit="receivePurchaseOrder" class="flex flex-wrap items-end gap-3">
                    <label class="block w-full max-w-sm">
                        <span class="text-sm font-medium text-gray-700">{{ __('Ta emot inköpsorder') }}</span>
                        <select wire:model="purchaseOrderId" class="{{ $input }}">
                            <option value="">{{ __('Välj inköpsorder') }}</option>
                            @foreach($awaiting as $order)
                                <option value="{{ $order->id }}">{{ $order->title() }} · {{ $order->supplier?->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-secondary-button type="submit">{{ __('Ta emot') }}</x-secondary-button>
                </form>
            @endif
        </div>

        @if($receipts->isEmpty())
            <x-ui.empty-state :title="__('Inga inleveranser')">{{ __('Skapa en inleverans när du beställer varor, eller direkt när de kommer. Du kan också göra den från en leverantörsfaktura.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($receipts as $receipt)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="receipt-{{ $receipt->id }}">
                        <span>
                            <span class="font-semibold tabular-nums">{{ __('Inleverans :number', ['number' => $receipt->number]) }}</span>
                            <span class="text-gray-700">{{ $receipt->supplier?->name }}</span>
                            <x-ui.badge :tone="$receipt->status === 'RECEIVED' ? 'success' : ($receipt->isOpen() ? 'accent' : 'neutral')">{{ $receipt->statusLabel() }}</x-ui.badge>
                            <span class="block text-sm text-gray-500">
                                {{ __('Rader: :count', ['count' => $receipt->lines->count()]) }}
                                @if($receipt->expected_on) · {{ __('väntas :date', ['date' => $receipt->expected_on->toDateString()]) }}@endif
                                @if($receipt->reference) · {{ $receipt->reference }}@endif
                                @if($receipt->purchaseOrder && $seesOrders) · <a href="{{ route('inventory.purchaseOrders.show', $receipt->purchaseOrder->id) }}" class="text-accent-700 underline">{{ $receipt->purchaseOrder->title() }}</a>@endif
                            </span>
                        </span>
                        <span class="flex flex-wrap gap-3 text-sm font-semibold">
                            @if($canCount && $receipt->isOpen())
                                <button type="button" wire:click="openReceive({{ $receipt->id }})" class="text-accent-700 underline">{{ __('Ta emot') }}</button>
                                @if($receipt->status === 'OPEN')
                                    <button type="button" wire:click="cancelReceipt({{ $receipt->id }})" wire:confirm="{{ __('Makulera inleveransen? Inget har tagits emot på den.') }}" class="text-gray-700 underline">{{ __('Makulera') }}</button>
                                @endif
                            @endif
                            @if($canPrint && $receipt->lines->sum('received_milli') > 0)
                                <button type="button" wire:click="printLabels({{ $receipt->id }})" class="text-accent-700 underline">{{ __('Etiketter för det som kom in') }}</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $receipts->links() }}</div>
        @endif
    </x-ui.card>
</div>
