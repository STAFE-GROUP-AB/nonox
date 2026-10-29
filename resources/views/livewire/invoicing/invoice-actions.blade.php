@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm')

<div class="space-y-6">
    <x-ui.card>
        <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd class="font-medium">{{ $invoice->isOverdue() ? __('Förfallen') : $invoice->status->label() }}</dd></div>
            <div><dt class="text-gray-500">{{ $invoice->isCredit() ? __('Att kreditera') : __('Att betala') }}</dt><dd class="font-medium"><x-ui.amount :money="$invoice->total_ore" /></dd></div>
            @unless($invoice->isCredit())
                <div><dt class="text-gray-500">{{ __('Förfallodag') }}</dt><dd class="font-medium tabular-nums">{{ $invoice->due_date->toDateString() }}</dd></div>
                <div><dt class="text-gray-500">{{ __('OCR-nummer') }}</dt><dd class="font-medium tabular-nums">{{ $invoice->ocr }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Betalt') }}</dt><dd class="font-medium"><x-ui.amount :money="$invoice->paid_ore" /></dd></div>
            @endunless
            <div><dt class="text-gray-500">{{ __('Bokförd') }}</dt><dd class="font-medium">
                @if($invoice->voucher)
                    <a href="{{ route('accounting.vouchers.show', $invoice->voucher) }}" class="text-accent-600 hover:text-accent-700">{{ $invoice->voucher->reference() }}</a>
                @else
                    {{ __('Vid betalning (kontantmetoden)') }}
                @endif
            </dd></div>
            <div><dt class="text-gray-500">{{ __('Skickad') }}</dt><dd class="font-medium">{{ $invoice->sent_at ? __(':date till :email', ['date' => $invoice->sent_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), 'email' => $invoice->sent_to]) : __('Inte än') }}</dd></div>
            @if($invoice->credits)
                <div><dt class="text-gray-500">{{ __('Krediterar') }}</dt><dd class="font-medium"><a href="{{ route('invoicing.invoices.show', $invoice->credits) }}" class="text-accent-600 hover:text-accent-700">{{ $invoice->credits->title() }}</a></dd></div>
            @endif
            @foreach($invoice->creditInvoices as $creditInvoice)
                <div><dt class="text-gray-500">{{ __('Krediterad med') }}</dt><dd class="font-medium"><a href="{{ $creditInvoice->isDraft() ? route('invoicing.invoices.edit', $creditInvoice) : route('invoicing.invoices.show', $creditInvoice) }}" class="text-accent-600 hover:text-accent-700">{{ $creditInvoice->title() }}</a></dd></div>
            @endforeach
        </dl>
        <div class="mt-5 flex flex-wrap gap-4 text-sm">
            <a href="{{ route('invoicing.invoices.pdf', $invoice) }}" target="_blank" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna PDF') }}</a>
        </div>
    </x-ui.card>

    @if($canSend)
        <x-ui.card :title="__('Skicka med e-post')">
            <form wire:submit="send" class="flex flex-wrap items-start gap-3">
                <div class="min-w-[16rem] flex-1">
                    <label for="send_email" class="sr-only">{{ __('E-post') }}</label>
                    <input id="send_email" type="email" class="{{ $input }}" wire:model="email" placeholder="{{ __('kund@exempel.se') }}">
                    <x-input-error for="email" class="mt-1" />
                </div>
                <x-button wire:loading.attr="disabled">{{ $invoice->sent_at ? __('Skicka igen') : __('Skicka') }}</x-button>
            </form>
            <p class="mt-2 text-sm text-gray-500">{{ __('Kunden får fakturan som PDF. Svar på mejlet går till företagets e-postadress.') }}</p>
        </x-ui.card>
    @endif

    @if($canPay)
        <x-ui.card :title="__('Registrera betalning')">
            <form wire:submit="registerPayment" class="grid gap-4 sm:grid-cols-[10rem_12rem_auto] sm:items-end">
                <x-ui.field for="paid_on" :label="__('Betaldag')" error="paid_on">
                    <input id="paid_on" type="date" class="{{ $input }} mt-1" wire:model="paidOn">
                </x-ui.field>
                <x-ui.field for="paid_amount" :label="__('Belopp')" error="amount">
                    <input id="paid_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="amount">
                </x-ui.field>
                <div><x-button wire:loading.attr="disabled">{{ __('Registrera') }}</x-button></div>
            </form>
            <p class="mt-2 text-sm text-gray-500">{{ __('Betalningen bokförs på 1930 Företagskonto i serie C.') }}</p>
        </x-ui.card>
    @endif

    @if($invoice->payments->isNotEmpty())
        <x-ui.card :title="__('Betalningar')">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($invoice->payments as $payment)
                    <li class="flex justify-between gap-4 py-2">
                        <span class="tabular-nums">{{ $payment->paid_on->toDateString() }}</span>
                        <a href="{{ route('accounting.vouchers.show', $payment->voucher_id) }}" class="text-accent-600 hover:text-accent-700">{{ $payment->voucher?->reference() }}</a>
                        <x-ui.amount :money="$payment->amount_ore" />
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if($canCredit)
        <x-ui.card :title="__('Kreditera')">
            <p class="text-sm text-gray-700">{{ __('Blev något fel kan fakturan krediteras. Då skapas en kreditfaktura som tar ut den, och du kan skicka en ny.') }}</p>
            <div class="mt-3" x-data="{ confirming: false }">
                <button type="button" class="text-sm font-medium text-accent-600 hover:text-accent-700" x-show="! confirming" x-on:click="confirming = true">{{ __('Kreditera fakturan') }}</button>
                <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3 text-sm">
                    <x-button type="button" wire:click="credit">{{ __('Ja, förbered kreditfaktura') }}</x-button>
                    <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                </span>
            </div>
        </x-ui.card>
    @endif
</div>
