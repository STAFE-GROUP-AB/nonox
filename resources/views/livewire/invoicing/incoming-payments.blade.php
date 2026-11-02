<div class="space-y-6">
    @if($canBook)
        <x-ui.card :title="__('Läs in bankens fil')" :description="__('Hämta aviseringen av inbetalningar i ISO 20022-format (camt.054) från internetbanken. Inbetalningar med OCR-nummer kopplas till rätt faktura.')">
            <div
                x-data="{ over: false }"
                x-on:dragover.prevent="over = true"
                x-on:dragleave.prevent="over = false"
                x-on:drop.prevent="over = false; $wire.upload('file', $event.dataTransfer.files[0])"
                :class="over ? 'border-accent-500 bg-accent-50' : 'border-gray-300'"
                class="rounded-lg border-2 border-dashed px-4 py-6 text-center text-sm"
            >
                <p class="text-gray-700">{{ __('Dra hit filen, eller') }}
                    <label for="bank_file" class="cursor-pointer font-semibold text-accent-600 hover:text-accent-700">{{ __('välj fil') }}</label>
                </p>
                <input id="bank_file" type="file" class="sr-only" wire:model="file" accept=".xml,application/xml,text/xml">
                <p class="mt-1 text-xs text-gray-500" wire:loading wire:target="file">{{ __('Läser filen…') }}</p>
            </div>
            @error('file') <p class="mt-2 text-sm text-accent-700" role="alert">{{ $message }}</p> @enderror
        </x-ui.card>
    @endif

    @if($import || $feed)
        <x-ui.card :title="$feed ? __('Inbetalningar från banken') : __('Inbetalningar i :name', ['name' => $import->file_name])" :description="$feed ? __('Hämtade automatiskt med NONOX Bank. Förslagen bygger på OCR-numret; kontrollera dem innan du bokför.') : __('Inläst :date av :name. Förslagen bygger på OCR-numret; kontrollera dem innan du bokför.', ['date' => $import->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), 'name' => $import->creator?->name])">
            @if($transactions->isEmpty())
                <p class="text-sm text-gray-600">{{ __('Filen innehöll inga nya inbetalningar.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-2 pr-3 w-8"><span class="sr-only">{{ __('Välj') }}</span></th>
                                <th class="py-2 pr-4 font-medium">{{ __('Bokföringsdag') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Betalare och referens') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Belopp') }}</th>
                                <th class="py-2 font-medium">{{ __('Faktura') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($transactions as $transaction)
                                <tr wire:key="transaction-{{ $transaction->id }}">
                                    <td class="py-2 pr-3">
                                        @if($canBook && ! $transaction->isBooked() && $transaction->invoice_id)
                                            <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" value="{{ $transaction->id }}" wire:model.live="selected" aria-label="{{ __('Välj inbetalning') }}">
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $transaction->booking_date->toDateString() }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="block">{{ $transaction->payer_name ?? __('Okänd betalare') }}</span>
                                        <span class="block text-xs text-gray-500">{{ $transaction->ocr ? __('OCR :ocr', ['ocr' => $transaction->ocr]) : $transaction->message }}</span>
                                    </td>
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="$transaction->amount_ore" />@if($transaction->foreign_currency !== null)<span class="block text-xs text-gray-500 tabular-nums">{{ App\Support\Money\Money::ofOre((int) $transaction->foreign_amount_minor)->format(withCurrency: false) }} {{ $transaction->foreign_currency }}</span>@endif</td>
                                    <td class="py-2">
                                        @if($transaction->isBooked())
                                            <a href="{{ route('invoicing.invoices.show', $transaction->invoice_id) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ $transaction->invoice?->title() }}</a>
                                            <span class="block text-xs text-gray-500">{{ __('Bokförd :voucher', ['voucher' => $transaction->payment?->voucher?->reference()]) }}</span>
                                        @elseif($canBook)
                                            <select class="w-full max-w-xs border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm" wire:change="assign({{ $transaction->id }}, $event.target.value)" aria-label="{{ __('Faktura för inbetalningen') }}">
                                                <option value="">{{ __('Ingen faktura, hanteras för hand') }}</option>
                                                @foreach($openInvoices as $invoice)
                                                    <option value="{{ $invoice->id }}" @selected($invoice->id === $transaction->invoice_id)>{{ $invoice->title() }} · {{ $invoice->customer_snapshot['name'] ?? '' }} · {{ $invoice->formatRemaining() }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            {{ $transaction->invoice?->title() ?? '–' }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('transaction') <p class="mt-2 text-sm text-accent-700" role="alert">{{ $message }}</p> @enderror

                @if($canBook)
                    <div class="mt-5 flex flex-wrap items-center justify-end gap-4">
                        <p class="text-sm text-gray-600">{{ __('Valda inbetalningar bokförs på bankens bokföringsdag i serie C, precis som när du registrerar en betalning på fakturan.') }}</p>
                        <x-button type="button" wire:click="book" wire:loading.attr="disabled" :disabled="$selected === []">{{ __('Bokför valda') }}</x-button>
                    </div>
                @endif
            @endif
        </x-ui.card>
    @endif

    @if($imports->count() > 1 || ($hasFeed && $imports->isNotEmpty()))
        <x-ui.card :title="__('Tidigare filer')">
            <ul class="divide-y divide-gray-100 text-sm">
                @if($hasFeed)
                    <li class="flex justify-between gap-4 py-2">
                        <button type="button" class="text-left text-accent-600 hover:text-accent-700" wire:click="$set('importId', 'bank')">{{ __('Från banken (NONOX Bank)') }}</button>
                        <span class="text-gray-500">{{ __('Hämtas automatiskt') }}</span>
                    </li>
                @endif
                @foreach($imports as $previous)
                    <li class="flex justify-between gap-4 py-2">
                        <button type="button" class="text-left text-accent-600 hover:text-accent-700" wire:click="$set('importId', '{{ $previous->id }}')">{{ $previous->file_name }}</button>
                        <span class="text-gray-500 tabular-nums">{{ $previous->created_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }} · {{ $previous->credit_count === 1 ? __('1 inbetalning') : __(':count inbetalningar', ['count' => $previous->credit_count]) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
