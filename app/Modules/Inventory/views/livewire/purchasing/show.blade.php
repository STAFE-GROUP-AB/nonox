@php
    use App\Modules\Inventory\Domain\Quantity;
    use App\Support\Money\Money;

    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $button = 'inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700';
    $secondary = 'inline-flex items-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-stone-50';
    $kr = fn (int $ore): string => Money::ofOre($ore)->format();
@endphp
<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-600">{{ __('Leverantör') }}</dt><dd class="font-semibold">{{ $order->supplier?->name }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Status') }}</dt><dd><x-ui.badge :tone="$order->status->tone()">{{ $order->status->label() }}</x-ui.badge>@if($order->version > 1) <span class="text-gray-600">{{ __('version :version', ['version' => $order->version]) }}</span>@endif</dd></div>
                <div><dt class="text-gray-600">{{ __('Källa') }}</dt><dd>{{ $order->source->label() }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Orderdatum') }}</dt><dd class="tabular-nums">{{ $order->order_date->toDateString() }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Önskat leveransdatum') }}</dt><dd class="tabular-nums">{{ $order->requested_delivery_on?->toDateString() ?? '–' }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Vår referens') }}</dt><dd>{{ $order->our_reference ?? '–' }}</dd></div>
                @if($order->sent_at)
                    <div class="sm:col-span-3"><dt class="text-gray-600">{{ __('Senast skickad') }}</dt><dd>{{ $order->sent_at->toDateString() }} · {{ $order->sent_method === 'EMAIL' ? __('e-post till :email', ['email' => $order->sent_to]) : __('utskriven') }}</dd></div>
                @endif
            </dl>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('inventory.purchaseOrders.pdf', $order->id) }}" target="_blank" class="{{ $secondary }}">{{ __('Visa PDF') }}</a>
                @if($order->isDraft())
                    @if($canManage)
                        <a href="{{ route('inventory.purchaseOrders.edit', $order->id) }}" class="{{ $secondary }}">{{ __('Ändra') }}</a>
                    @endif
                    @if($canSend && ! $sending)
                        <button type="button" wire:click="openSend" class="{{ $button }}">{{ __('Skicka till leverantören') }}</button>
                    @endif
                @endif
                @if($order->status->awaitsGoods() && $canManage)
                    <button type="button" wire:click="revise" wire:confirm="{{ __('Göra en ny version av ordern? Den skickade versionen sparas, och den nya måste skickas till leverantören igen.') }}" class="{{ $secondary }}">{{ __('Ny version') }}</button>
                @endif
                @if(($order->status->awaitsGoods() || ($order->isDraft() && $order->wasSent())) && $canReceive)
                    <button type="button" wire:click="receive" class="{{ $button }}">{{ __('Ta emot varor') }}</button>
                @endif
                @if($order->status->isOpen() && $canManage && $order->rows->sum('received_milli') === 0)
                    <button type="button" wire:click="cancelOrder" wire:confirm="{{ __('Makulera inköpsordern? Numret finns kvar men ordern gäller inte längre. Har den skickats behöver du själv meddela leverantören.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Makulera') }}</button>
                @endif
            </div>
        </div>

        @if($order->isDraft() && $needsApproval && ! $canSend)
            <p class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{{ __('Ordern är över företagets attestgräns på :amount. En attestant eller administratör skickar den.', ['amount' => $kr((int) $approvalLimit)]) }}</p>
        @endif
    </x-ui.card>

    @if($sending)
        <x-ui.card :title="__('Skicka inköpsordern')" :description="__('Så här ser ordern ut för leverantören. Kontrollera PDF:en och mottagaren innan du skickar.')">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <iframe src="{{ route('inventory.purchaseOrders.pdf', $order->id) }}" title="{{ $order->title() }}" class="h-160 w-full rounded-xl border border-gray-200 bg-white"></iframe>
                <form wire:submit="sendEmail" class="space-y-4">
                    <x-ui.field for="po-send-email" :label="__('Mottagare')" error="email" :hint="__('Mejlet skickas i företagets namn. Svar går till :email.', ['email' => auth()->user()->currentCompany()->email ?: __('företagets e-postadress')])">
                        <input id="po-send-email" type="email" wire:model="email" class="{{ $input }}" autocomplete="off">
                    </x-ui.field>
                    <p class="text-sm text-gray-700">{{ __('Belopp exkl. moms: :amount', ['amount' => $kr($order->totalOre())]) }}</p>
                    <div class="flex flex-wrap gap-3">
                        <x-button type="submit" wire:loading.attr="disabled">{{ __('Skicka e-post') }}</x-button>
                        <x-secondary-button type="button" wire:click="markPrinted" wire:confirm="{{ __('Markera ordern som skickad? Skriv ut PDF:en och skicka den på annat sätt.') }}">{{ __('Utskriven, markera som skickad') }}</x-secondary-button>
                        <x-secondary-button type="button" wire:click="closeSend">{{ __('Avbryt') }}</x-secondary-button>
                    </div>
                </form>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Rader')">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-gray-600">
                        <th class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Leverantörens art.nr') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('À-pris') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Väntas') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Mottaget') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($order->rows as $row)
                        @php($stockCode = $row->article ? $row->article->stockUnitCode() : '')
                        <tr wire:key="po-row-{{ $row->id }}">
                            <td class="py-2 pr-4"><span class="tabular-nums">{{ $row->article?->number }}</span> {{ $row->description }}</td>
                            <td class="py-2 pr-4">{{ $row->supplier_article_number }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">
                                {{ Quantity::format(Quantity::parse($row->entered_quantity)) }} {{ $row->unit?->code }}
                                @if($row->unit?->code !== $stockCode)
                                    <span class="block text-gray-600">{{ __('≈ :quantity :unit', ['quantity' => Quantity::format($row->quantity_milli), 'unit' => $stockCode]) }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ Money::ofOre($row->price_ore)->format(withCurrency: false) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ Money::ofOre($row->valueOre())->format(withCurrency: false) }}</td>
                            <td class="py-2 pr-4 tabular-nums">{{ $row->expected_on?->toDateString() }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ Quantity::format($row->received_milli) }} {{ $stockCode }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-stone-300 font-semibold">
                        <td class="py-2 pr-4" colspan="4">{{ __('Summa exkl. moms') }}</td>
                        <td class="py-2 pr-4 text-right tabular-nums">{{ $kr($order->totalOre()) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @if($order->delivery_address || $order->notes)
            <div class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                @if($order->delivery_address)<div><p class="text-gray-600">{{ __('Leveransadress') }}</p><p>{!! nl2br(e($order->delivery_address)) !!}</p></div>@endif
                @if($order->notes)<div><p class="text-gray-600">{{ __('Meddelande till leverantören') }}</p><p>{!! nl2br(e($order->notes)) !!}</p></div>@endif
            </div>
        @endif
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('Skickade versioner')">
            @if($order->versions->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Ordern har inte skickats än.') }}</p>
            @else
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach($order->versions as $version)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="po-version-{{ $version->id }}">
                            <span>{{ __('Version :version', ['version' => $version->version]) }} · {{ $version->sent_at->toDateString() }} · {{ $version->method === 'EMAIL' ? $version->sent_to : __('utskriven') }}</span>
                            <a href="{{ route('inventory.purchaseOrders.pdf', ['purchaseOrder' => $order->id, 'version' => $version->version]) }}" target="_blank" class="font-semibold text-accent-700 underline">{{ __('PDF') }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Inleveranser')">
            @if($order->receipts->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Inget har tagits emot än. Lagret ökar när varorna tas emot.') }}</p>
            @else
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach($order->receipts as $receipt)
                        <li class="py-2" wire:key="po-receipt-{{ $receipt->id }}">
                            <a href="{{ route('inventory.receipts', ['visa' => 'alla']) }}" class="font-semibold text-accent-700 underline">{{ __('Inleverans :number', ['number' => $receipt->number]) }}</a>
                            <x-ui.badge :tone="$receipt->status === 'RECEIVED' ? 'success' : ($receipt->isOpen() ? 'accent' : 'neutral')">{{ $receipt->statusLabel() }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('Leverantörsfakturor')">
            @if($order->supplierInvoices->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Ingen faktura är kopplad. Koppla fakturan när den kommer, från fakturans sida under Leverantörsfakturor.') }}</p>
            @else
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach($order->supplierInvoices as $invoice)
                        <li class="py-2" wire:key="po-invoice-{{ $invoice->id }}">
                            <a href="{{ route('payables.invoices.show', $invoice) }}" class="font-semibold text-accent-700 underline">{{ $invoice->title() }}</a>
                            <span class="block text-gray-600 tabular-nums">{{ $invoice->invoice_date->toDateString() }} · {{ $invoice->total_ore->format() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</div>
