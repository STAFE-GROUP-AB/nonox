@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div class="space-y-6">
    <x-ui.card>
        <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd class="font-medium">{{ $invoice->isOverdue() ? __('Förfallen') : $invoice->statusLabel() }}</dd></div>
            <div><dt class="text-gray-500">{{ $invoice->isCredit() ? __('Att kreditera') : __('Att betala') }}</dt><dd class="font-medium">
                @if($invoice->isForeign())
                    <span class="tabular-nums">{{ $invoice->formatForeign((int) $invoice->foreign_total_minor) }}</span>
                    <span class="block font-normal text-gray-600"><x-ui.amount :money="$invoice->total_ore" /> · {{ __('kurs :rate', ['rate' => $invoice->bookingRate()?->format()]) }}@if($invoice->exchange_rate_source === 'riksbank') ({{ __('Riksbanken :date', ['date' => $invoice->exchange_rate_date?->toDateString()]) }})@endif</span>
                @else
                    <x-ui.amount :money="$invoice->total_ore" />
                @endif
            </dd></div>
            @unless($invoice->isCredit())
                <div><dt class="text-gray-500">{{ __('Förfallodag') }}</dt><dd class="font-medium tabular-nums">{{ $invoice->due_date->toDateString() }}</dd></div>
                <div><dt class="text-gray-500">{{ __('OCR-nummer') }}</dt><dd class="font-medium tabular-nums">{{ $invoice->ocr }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Betalt') }}</dt><dd class="font-medium">
                    @if($invoice->isForeign())
                        <span class="tabular-nums">{{ $invoice->formatForeign($invoice->foreign_paid_minor) }}</span>
                        <span class="block font-normal text-gray-600">{{ __('Kvar: :remaining', ['remaining' => $invoice->formatRemaining()]) }}</span>
                    @else
                        <x-ui.amount :money="$invoice->paid_ore->minus($invoice->credited_ore)" />
                    @endif
                </dd></div>
                @unless($invoice->credited_ore->isZero())
                    <div><dt class="text-gray-500">{{ __('Krediterat') }}</dt><dd class="font-medium">
                        <x-ui.amount :money="$invoice->credited_ore" />
                        @unless($invoice->remaining()->isZero())
                            <span class="block font-normal text-gray-600">{{ __('Kvar: :remaining', ['remaining' => $invoice->formatRemaining()]) }}</span>
                        @endunless
                    </dd></div>
                @endunless
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

    @if($canRemind || $invoice->reminders->isNotEmpty())
        <x-ui.card :title="__('Påminnelser')">
            @if($invoice->reminders->isNotEmpty())
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach($invoice->reminders as $reminder)
                        <li class="py-2">{{ __('Påminnelse :sequence skickad :date till :email, betala senast :pay_by', ['sequence' => $reminder->sequence, 'date' => $reminder->sent_on->toDateString(), 'email' => $reminder->sent_to, 'pay_by' => $reminder->pay_by->toDateString()]) }}</li>
                    @endforeach
                </ul>
            @endif
            @if($canRemind)
                <p class="mt-2 text-sm text-gray-700">{{ __('Fakturan har förfallit. En påminnelse skickas till e-postadressen ovan med fakturan bifogad och tio dagar att betala.') }}</p>
                <x-button type="button" class="mt-3" wire:click="remind" wire:loading.attr="disabled">{{ __('Skicka påminnelse') }}</x-button>
            @endif
        </x-ui.card>
    @endif

    @if($canPay)
        <x-ui.card :title="__('Registrera betalning')">
            <form wire:submit="registerPayment" class="grid grid-cols-2 gap-4">
                <x-ui.field for="paid_on" :label="__('Betaldag')" error="paid_on">
                    <input id="paid_on" type="date" class="{{ $input }} mt-1" @if($invoice->isForeign()) wire:model.change="paidOn" @else wire:model="paidOn" @endif>
                </x-ui.field>
                @if($invoice->isForeign())
                    <x-ui.field for="paid_foreign" :label="__('Belopp i :currency', ['currency' => $invoice->invoice_currency])" error="foreign_amount">
                        <input id="paid_foreign" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.blur="foreignAmount">
                    </x-ui.field>
                    <x-ui.field for="paid_amount" :label="__('Kronor in på banken')" error="amount" :hint="$rateDate !== '' ? __('Ifyllt med Riksbankens kurs :rate. Ändra till beloppet på kontoutdraget.', ['rate' => $rateDate]) : __('Skriv beloppet på kontoutdraget.')" class="col-span-2">
                        <input id="paid_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.blur="amount">
                    </x-ui.field>
                    @if($exchangePreview)
                        <p class="col-span-2 text-sm text-gray-700">
                            {{ __('Kundfordran minskar med :settled (bokförd kurs).', ['settled' => App\Support\Money\Money::ofOre($exchangePreview['settled'])->format()]) }}
                            @if($exchangePreview['difference'] > 0)
                                {{ __('Kursvinst :amount bokförs på 3960.', ['amount' => App\Support\Money\Money::ofOre($exchangePreview['difference'])->format()]) }}
                            @elseif($exchangePreview['difference'] < 0)
                                {{ __('Kursförlust :amount bokförs på 7960.', ['amount' => App\Support\Money\Money::ofOre(-$exchangePreview['difference'])->format()]) }}
                            @else
                                {{ __('Ingen kursdifferens.') }}
                            @endif
                        </p>
                    @endif
                @else
                    <x-ui.field for="paid_amount" :label="__('Belopp')" error="amount">
                        <input id="paid_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="amount">
                    </x-ui.field>
                @endif
                <div class="col-span-2"><x-button wire:loading.attr="disabled">{{ __('Registrera betalningen') }}</x-button></div>
            </form>
            <p class="mt-2 text-sm text-gray-500">{{ __('Betalningen bokförs på 1930 Företagskonto i serie C.') }}</p>
        </x-ui.card>
    @endif

    @if($invoice->tax_reduction !== null && $invoice->isCredit())
        <x-ui.card :title="__(':type hos Skatteverket', ['type' => App\Domain\Invoicing\TaxReduction::label($invoice->tax_reduction)])">
            <p class="text-sm text-gray-700">{{ __('Kreditfakturan tar tillbaka :amount av avdraget.', ['amount' => $invoice->tax_reduction_ore->negated()->format()]) }}</p>
            @if($invoice->tax_reduction_repayable_ore->isZero())
                <p class="mt-1 text-xs text-gray-500">{{ __('Skatteverket hade inte betalat ut den delen, så den har tagits bort från fordran på 1513. Har du redan begärt utbetalningen i Skatteverkets e-tjänst, ändra eller dra tillbaka begäran.') }}</p>
            @else
                <p class="mt-2 text-sm font-medium text-gray-900">{{ __('Skatteverket har redan betalat ut :amount av det. Det är nu en skuld till Skatteverket på konto 2890.', ['amount' => $invoice->tax_reduction_repayable_ore->format()]) }}</p>
                <p class="mt-1 text-sm text-gray-700">{{ __('Anmäl ändringen till Skatteverket utan dröjsmål när kunden har fått pengarna tillbaka. Skatteverket beslutar sedan om återbetalning. Registrera betalningen här när du har betalat.') }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ __('Återbetalt :repaid av :repayable.', ['repaid' => $invoice->tax_reduction_repaid_ore->format(), 'repayable' => $invoice->tax_reduction_repayable_ore->format()]) }}</p>
                @if($canTaxRepay)
                    <form wire:submit="registerTaxReductionRepayment" class="mt-4 grid grid-cols-2 gap-4">
                        <x-ui.field for="repay_paid_on" :label="__('Betaldag')"><input id="repay_paid_on" type="date" class="{{ $input }} mt-1" wire:model="repayPaidOn"></x-ui.field>
                        <x-ui.field for="repay_amount" :label="__('Belopp')" error="repay_amount"><input id="repay_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="repayAmount"></x-ui.field>
                        <div class="col-span-2"><x-button wire:loading.attr="disabled">{{ __('Registrera återbetalningen') }}</x-button></div>
                    </form>
                @endif
            @endif
        </x-ui.card>
    @endif

    @if($invoice->tax_reduction !== null && ! $invoice->isCredit())
        <x-ui.card :title="__(':type hos Skatteverket', ['type' => App\Domain\Invoicing\TaxReduction::label($invoice->tax_reduction)])">
            <p class="text-sm text-gray-700">{{ __('Begärt :claimed, mottaget :paid.', ['claimed' => $invoice->tax_reduction_ore->format(), 'paid' => $invoice->tax_reduction_paid_ore->format()]) }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ __('Begär utbetalningen i Skatteverkets e-tjänst Rot och rut när kunden har betalat sin del. Registrera Skatteverkets betalning här när den kommer.') }}</p>
            @if($taxRecheck !== null)
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" role="alert">{{ __('Kunden betalade :year. Med det årets procentsats och tak blir avdraget :allowed, inte :invoiced som på fakturan. Begär högst :allowed hos Skatteverket; mellanskillnaden betalar kunden.', ['year' => $taxRecheck['year'], 'allowed' => App\Support\Money\Money::ofOre($taxRecheck['allowed'])->format(), 'invoiced' => App\Support\Money\Money::ofOre($taxRecheck['invoiced'])->format()]) }}</p>
            @endif
            @if($canTaxPay)
                <form wire:submit="registerTaxReductionPayment" class="mt-4 grid grid-cols-2 gap-4">
                    <x-ui.field for="tax_paid_on" :label="__('Betaldag')"><input id="tax_paid_on" type="date" class="{{ $input }} mt-1" wire:model="taxPaidOn"></x-ui.field>
                    <x-ui.field for="tax_amount" :label="__('Belopp')" error="tax_amount"><input id="tax_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="taxAmount"></x-ui.field>
                    <div class="col-span-2"><x-button wire:loading.attr="disabled">{{ __('Registrera Skatteverkets betalning') }}</x-button></div>
                </form>
            @endif
        </x-ui.card>
    @endif

    @if($refundLeft !== null)
        <x-ui.card :title="__('Betala tillbaka till kunden')">
            <p class="text-sm text-gray-700">{{ __('Kunden hade redan betalat det som krediterades och har :amount att få tillbaka. Beloppet står som en skuld till kunden i kundreskontran, med kredit på 1510, tills det är återbetalt.', ['amount' => $refundLeft]) }}</p>
            @if($canRefund)
                <form wire:submit="registerRefund" class="mt-4 grid grid-cols-2 gap-4">
                    <x-ui.field for="refund_paid_on" :label="__('Betaldag')"><input id="refund_paid_on" type="date" class="{{ $input }} mt-1" wire:model="refundPaidOn"></x-ui.field>
                    @if($invoice->isForeign())
                        <x-ui.field for="refund_foreign" :label="__('Belopp i :currency', ['currency' => $invoice->invoice_currency])" error="refund_foreign_amount">
                            <input id="refund_foreign" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="refundForeignAmount">
                        </x-ui.field>
                        <x-ui.field for="refund_amount" :label="__('Kronor ut från banken')" error="refund_amount" :hint="__('Skriv beloppet på kontoutdraget.')" class="col-span-2">
                            <input id="refund_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="refundAmount">
                        </x-ui.field>
                    @else
                        <x-ui.field for="refund_amount" :label="__('Belopp')" error="refund_amount">
                            <input id="refund_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="refundAmount">
                        </x-ui.field>
                    @endif
                    <div class="col-span-2"><x-button wire:loading.attr="disabled">{{ __('Registrera återbetalningen') }}</x-button></div>
                </form>
                <p class="mt-2 text-sm text-gray-500">{{ __('Återbetalningen bokförs från 1930 Företagskonto i serie C.') }}</p>
            @endif
        </x-ui.card>
    @endif

    @if($invoice->payments->isNotEmpty())
        <x-ui.card :title="$invoice->isCredit() ? __('Återbetalningar') : __('Betalningar')">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($invoice->payments as $payment)
                    <li class="flex justify-between gap-4 py-2">
                        <span class="tabular-nums">{{ $payment->paid_on->toDateString() }}</span>
                        <a href="{{ route('accounting.vouchers.show', $payment->voucher_id) }}" class="text-accent-600 hover:text-accent-700">{{ $payment->voucher?->reference() }}</a>
                        <span class="text-right">
                            <x-ui.amount :money="$payment->amount_ore" />
                            @if($payment->foreign_amount_minor !== null)
                                <span class="block text-gray-500 tabular-nums">{{ $invoice->formatForeign($payment->foreign_amount_minor) }}@if($payment->exchange_difference_ore !== 0) · {{ $payment->exchange_difference_ore > 0 ? __('kursvinst :amount', ['amount' => App\Support\Money\Money::ofOre($payment->exchange_difference_ore)->format()]) : __('kursförlust :amount', ['amount' => App\Support\Money\Money::ofOre(-$payment->exchange_difference_ore)->format()]) }}@endif</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if($canCredit)
        <x-ui.card :title="__('Kreditera')">
            @if($invoice->tax_reduction !== null && ! $invoice->tax_reduction_paid_ore->isZero())
                <p class="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ __('Skatteverket har betalat ut :amount i :type för fakturan. Krediterar du avdraget blir den delen en skuld till Skatteverket, och du ska anmäla ändringen till Skatteverket utan dröjsmål.', ['amount' => $invoice->tax_reduction_paid_ore->format(), 'type' => App\Domain\Invoicing\TaxReduction::label($invoice->tax_reduction)]) }}</p>
            @endif
            <p class="text-sm text-gray-700">{{ __('Blev något fel, eller ska kunden få ett prisavdrag, kan fakturan krediteras, hel eller till en del. Då skapas en kreditfaktura som minskar den med sitt belopp. Ändra raderna i utkastet om bara en del ska krediteras. Har kunden redan betalat får kunden pengar tillbaka, som du registrerar på kreditfakturan.') }}</p>
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
