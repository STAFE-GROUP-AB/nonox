@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6">
    <x-ui.card>
        <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd @class(['font-medium', 'text-accent-700' => $invoice->isOverdue()])>{{ $invoice->isOverdue() ? __('Förfallen') : $invoice->status->label() }}</dd></div>
            <div><dt class="text-gray-500">{{ $invoice->isCredit() ? __('Kreditbelopp') : __('Att betala') }}</dt><dd class="font-medium"><x-ui.amount :money="$invoice->total_ore" />
                @if($invoice->isForeign())
                    <span class="block font-normal text-gray-600 tabular-nums">{{ $invoice->formatForeign((int) $invoice->foreign_total_minor) }} · {{ __('kurs :rate', ['rate' => $invoice->bookingRate()?->format()]) }}</span>
                @endif
            </dd></div>
            @if($invoice->vat_treatment->buyerIsLiable())
                <div class="sm:col-span-2"><dt class="text-gray-500">{{ __('Moms') }}</dt><dd class="font-medium">{{ $invoice->vat_treatment->label() }}</dd></div>
            @endif
            <div><dt class="text-gray-500">{{ __('Förfallodag') }}</dt><dd class="font-medium tabular-nums">{{ $invoice->due_date->toDateString() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Kvar att betala') }}</dt><dd class="font-medium"><x-ui.amount :money="$invoice->remaining()" />
                @if($invoice->isForeign())
                    <span class="block font-normal text-gray-600 tabular-nums">{{ $invoice->formatForeign((int) $invoice->foreignRemaining()) }}</span>
                @endif
            </dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500">{{ __('Betala till') }}</dt><dd class="font-medium tabular-nums">
                {{ $invoice->supplier?->paymentDetails() ?? __('Ingen betalningsuppgift i leverantörsregistret') }}
                @if($invoice->ocr)
                    <span class="block font-normal text-gray-600">{{ __('OCR :ocr', ['ocr' => $invoice->ocr]) }}</span>
                @elseif($invoice->invoice_number)
                    <span class="block font-normal text-gray-600">{{ __('Meddelande: faktura :number', ['number' => $invoice->invoice_number]) }}</span>
                @endif
            </dd></div>
            <div><dt class="text-gray-500">{{ __('Leverantörens fakturanummer') }}</dt><dd class="font-medium">{{ $invoice->invoice_number ?? '–' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Bokförd') }}</dt><dd class="font-medium">
                @if($invoice->voucher)
                    <a href="{{ route('accounting.vouchers.show', $invoice->voucher) }}" class="text-accent-600 hover:text-accent-700">{{ $invoice->voucher->reference() }}</a>
                @else
                    {{ __('Vid betalning (kontantmetoden)') }}
                @endif
            </dd></div>
            <div><dt class="text-gray-500">{{ __('Registrerad av') }}</dt><dd class="font-medium">{{ $invoice->registeredBy?->name }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Attesterad av') }}</dt><dd class="font-medium">{{ $invoice->approvedBy?->name ?? __('Inte än') }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card :title="__('Kontering')">
        <ul class="divide-y divide-gray-100 text-sm">
            @foreach($invoice->rows as $row)
                <li class="flex justify-between gap-4 py-2">
                    <span class="min-w-0">
                        <span class="tabular-nums font-medium">{{ $row->account?->number }}</span> {{ $row->account?->name }}
                        <span class="block text-gray-500">{{ collect([$row->description, $row->costCenter ? __('Kostnadsställe :code', ['code' => $row->costCenter->code]) : null, $row->project ? __('Projekt :code', ['code' => $row->project->code]) : null])->filter()->implode(' · ') }}</span>
                    </span>
                    <x-ui.amount :money="$row->amount_ore" />
                </li>
            @endforeach
            @unless($invoice->vat_ore->isZero())
                <li class="flex justify-between gap-4 py-2"><span><span class="tabular-nums font-medium">2641</span> {{ __('Ingående moms') }}</span><x-ui.amount :money="$invoice->vat_ore" /></li>
            @endunless
            @foreach($buyerVat as $rate => $vat)
                <li class="flex justify-between gap-4 py-2 text-gray-600">
                    <span>{{ __('Moms :rate % som du redovisar själv, utgående :output och ingående :input', ['rate' => $rate / 100, 'output' => $invoice->vat_treatment->outputVatAccount($rate), 'input' => $invoice->vat_treatment->inputVatAccount()]) }}</span>
                    <x-ui.amount :money="App\Support\Money\Money::ofOre($vat)" />
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    @if($canApprove)
        <x-ui.card :title="__('Attestera')">
            <p class="text-sm text-gray-700">{{ __('Kontrollera att varorna eller tjänsterna är levererade och att beloppet stämmer. Därefter kan fakturan betalas.') }}</p>
            <x-button type="button" class="mt-3" wire:click="approve" wire:loading.attr="disabled">{{ __('Attestera fakturan') }}</x-button>
            <x-input-error for="approve" class="mt-2" />
        </x-ui.card>
    @elseif($blockedBySegregation)
        <x-ui.card :title="__('Attestera')">
            <p class="text-sm text-gray-700">{{ __('Du registrerade fakturan, så någon annan med rätt att attestera behöver godkänna den. Är ni bara en person i företaget kan en ägare stänga av kravet under Leverantörsfakturor.') }}</p>
        </x-ui.card>
    @endif

    @if($canPay && $invoice->isCredit())
        <x-ui.card :title="__('Kvitta mot en faktura')">
            @if($offsetInvoices->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Det finns ingen attesterad, obetald faktura från samma leverantör att kvitta mot.') }}</p>
            @else
                <form wire:submit="offset" class="grid gap-4">
                    <x-ui.field for="offset_invoice" :label="__('Faktura')" error="offset">
                        <select id="offset_invoice" class="{{ $input }} mt-1" wire:model="offsetInvoiceId">
                            <option value="">{{ __('Välj faktura…') }}</option>
                            @foreach($offsetInvoices as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->title() }} · {{ $candidate->invoice_number }} · {{ $candidate->remaining()->format() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <div><x-button wire:loading.attr="disabled">{{ __('Kvitta') }}</x-button></div>
                </form>
                <p class="mt-2 text-sm text-gray-500">{{ __('Kreditnotan dras av från fakturan. Inga pengar flyttas; kvittningen bokförs i serie E.') }}</p>
            @endif
        </x-ui.card>
    @endif

    @if($canPay)
        <x-ui.card :title="$invoice->isCredit() ? __('Registrera återbetalning') : __('Registrera betalning')">
            <form wire:submit="pay" class="grid gap-4 sm:grid-cols-2">
                <x-ui.field for="supplier_paid_on" :label="__('Betaldag')" error="paid_on">
                    <input id="supplier_paid_on" type="date" class="{{ $input }} mt-1" @if($invoice->isForeign()) wire:model.change="paidOn" @else wire:model="paidOn" @endif>
                </x-ui.field>
                @if($invoice->isForeign())
                    <x-ui.field for="supplier_paid_foreign" :label="__('Belopp i :currency', ['currency' => $invoice->invoice_currency])" error="foreign_amount">
                        <input id="supplier_paid_foreign" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.blur="foreignAmount">
                    </x-ui.field>
                    <x-ui.field for="supplier_paid_amount" :label="$invoice->isCredit() ? __('Kronor in på banken') : __('Kronor som banken drog')" error="amount" :hint="$rateDate !== '' ? __('Ifyllt med Riksbankens kurs :rate. Ändra till beloppet på kontoutdraget.', ['rate' => $rateDate]) : __('Skriv beloppet på kontoutdraget.')" class="sm:col-span-2">
                        <input id="supplier_paid_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.blur="amount">
                    </x-ui.field>
                    @if($exchangePreview)
                        <p class="text-sm text-gray-700 sm:col-span-2">
                            {{ __('Skulden minskar med :settled (bokförd kurs).', ['settled' => App\Support\Money\Money::ofOre($exchangePreview['settled'])->format()]) }}
                            @if($exchangePreview['difference'] > 0)
                                {{ __('Kursförlust :amount bokförs på 7960.', ['amount' => App\Support\Money\Money::ofOre($exchangePreview['difference'])->format()]) }}
                            @elseif($exchangePreview['difference'] < 0)
                                {{ __('Kursvinst :amount bokförs på 3960.', ['amount' => App\Support\Money\Money::ofOre(-$exchangePreview['difference'])->format()]) }}
                            @else
                                {{ __('Ingen kursdifferens.') }}
                            @endif
                        </p>
                    @endif
                @else
                    <x-ui.field for="supplier_paid_amount" :label="__('Belopp')" error="amount">
                        <input id="supplier_paid_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="amount">
                    </x-ui.field>
                @endif
                <div class="sm:col-span-2"><x-button wire:loading.attr="disabled">{{ __('Registrera betalningen') }}</x-button></div>
            </form>
            <p class="mt-2 text-sm text-gray-500">{{ $invoice->isCredit() ? __('Pengarna från leverantören bokförs på 1930 Företagskonto i serie E.') : __('Betalningen bokförs från 1930 Företagskonto i serie E.') }}</p>
        </x-ui.card>
    @endif

    @if($invoice->payments->isNotEmpty())
        <x-ui.card :title="__('Betalningar')">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($invoice->payments as $payment)
                    <li class="flex justify-between gap-4 py-2">
                        <span class="tabular-nums">{{ $payment->paid_on->toDateString() }}</span>
                        <a href="{{ route('accounting.vouchers.show', $payment->voucher_id) }}" class="text-accent-600 hover:text-accent-700">{{ $payment->voucher?->reference() }}</a>
                        <span class="text-right">
                            <x-ui.amount :money="$payment->amount_ore" />
                            @if($payment->foreign_amount_minor !== null)
                                <span class="block text-gray-500 tabular-nums">{{ $invoice->formatForeign($payment->foreign_amount_minor) }}@if($payment->exchange_difference_ore !== 0) · {{ $payment->exchange_difference_ore > 0 ? __('kursförlust :amount', ['amount' => App\Support\Money\Money::ofOre($payment->exchange_difference_ore)->format()]) : __('kursvinst :amount', ['amount' => App\Support\Money\Money::ofOre(-$payment->exchange_difference_ore)->format()]) }}@endif</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
