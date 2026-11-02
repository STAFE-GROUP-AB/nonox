{{-- Rows of answers from the bank about payments out; $rows, $canHandle, $prefix. --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-gray-500">
                <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                <th class="py-2 pr-4 font-medium">{{ __('Bankens svar') }}</th>
                <th class="py-2 pr-4 font-medium">{{ __('Betalning') }}</th>
                <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                <th class="py-2 font-medium">{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($rows as $response)
                @php($invoice = $response->item?->invoice)
                <tr wire:key="{{ $prefix }}-{{ $response->id }}" class="align-top">
                    <td class="py-2 pr-4 tabular-nums">{{ $response->date?->toDateString() ?? '–' }}</td>
                    <td class="py-2 pr-4">
                        <span class="block">{{ $response->kindLabel() }}</span>
                        @if($response->reason())
                            <span class="block text-xs text-gray-600">{{ $response->reason() }}</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4">
                        @if($invoice)
                            <a href="{{ route('payables.invoices.show', $invoice) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $invoice->title() }}</a>
                            <span class="block text-xs text-gray-500">{{ $invoice->supplierName() }}@if($response->batch) · {{ $response->batch->title() }}@endif</span>
                        @elseif($response->batch)
                            <span class="block">{{ __(':title, hela filen', ['title' => $response->batch->title()]) }}</span>
                        @else
                            <span class="block">{{ $response->creditor_name ?? __('Okänd mottagare') }}</span>
                            <span class="block text-xs text-gray-500">{{ $response->end_to_end_id ? __('Referens :reference', ['reference' => $response->end_to_end_id]) : ($response->creditor_account ?? '') }}</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4 text-right">
                        @if($response->amount_ore)
                            <x-ui.amount :money="$response->amount_ore" />
                        @else
                            –
                        @endif
                    </td>
                    <td class="py-2">
                        <x-ui.badge :tone="$response->status->tone()">{{ $response->status->label() }}</x-ui.badge>
                        @if($response->match === \App\Models\PaymentResponse::BY_AMOUNT && $response->status->isOpen())
                            <span class="block text-xs text-gray-600">{{ __('Förslag efter belopp, datum och mottagare. Kontrollera innan du bekräftar.') }}</span>
                        @endif
                        @if($response->noteText())
                            <span class="block text-xs text-gray-600">{{ $response->noteText() }}</span>
                        @endif
                        @if($canHandle && $response->status->isOpen())
                            <span x-data="{ confirming: null }" class="mt-1 flex flex-wrap items-center gap-3">
                                @if($response->status === \App\Domain\Payables\PaymentResponseStatus::Suggested)
                                    <button type="button" class="font-medium text-accent-600 hover:text-accent-700" x-show="! confirming" x-on:click="confirming = 'confirm'">{{ __('Bekräfta') }}</button>
                                @endif
                                <button type="button" class="text-gray-600 underline" x-show="! confirming" x-on:click="confirming = 'dismiss'">{{ __('Avfärda') }}</button>
                                <span x-show="confirming === 'confirm'" x-cloak class="flex flex-wrap items-center gap-2">
                                    <span class="text-gray-700">{{ $response->kind === \App\Support\Iso20022\OutgoingPaymentEvent::BOOKED ? __('Betalningen bokförs i serie E på bankens bokföringsdag. Det går inte att ångra; ett fel rättas med en rättelseverifikation.') : __('Bankens svar förs in på betalningen.') }}</span>
                                    <x-button type="button" wire:click="confirm({{ $response->id }})" wire:loading.attr="disabled">{{ __('Ja, bekräfta') }}</x-button>
                                    <button type="button" class="text-gray-600 underline" x-on:click="confirming = null">{{ __('Avbryt') }}</button>
                                </span>
                                <span x-show="confirming === 'dismiss'" x-cloak class="flex flex-wrap items-center gap-2">
                                    <span class="text-gray-700">{{ __('Inget bokförs. Gör det när du har hanterat betalningen på annat sätt.') }}</span>
                                    <button type="button" class="font-medium text-accent-700 underline" wire:click="dismiss({{ $response->id }})">{{ __('Ja, avfärda') }}</button>
                                    <button type="button" class="text-gray-600 underline" x-on:click="confirming = null">{{ __('Avbryt') }}</button>
                                </span>
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
