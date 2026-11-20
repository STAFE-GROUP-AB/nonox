@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
@endphp

<div class="space-y-6">
    @if($document)
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="text-sm text-gray-700">
                    {{ __('Status') }}: <span class="font-semibold">{{ $document->status->label() }}</span>
                    @if($document->sent_at) · {{ __('skickad :date till :email', ['date' => $document->sent_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), 'email' => $document->sent_to]) }} @endif
                    @if($document->fromQuote) · <a href="{{ route('invoicing.quotes.show', $document->fromQuote) }}" class="text-accent-600 hover:text-accent-700">{{ $document->fromQuote->title() }}</a> @endif
                    @foreach($document->orders as $order) · <a href="{{ route('invoicing.orders.show', $order) }}" class="text-accent-600 hover:text-accent-700">{{ $order->title() }}</a> @endforeach
                    @if($document->invoice) · <a href="{{ $document->invoice->isDraft() ? route('invoicing.invoices.edit', $document->invoice) : route('invoicing.invoices.show', $document->invoice) }}" class="text-accent-600 hover:text-accent-700">{{ $document->invoice->title() }}</a> @endif
                </p>
                <a href="{{ route($routeBase.'.pdf', $document) }}" target="_blank" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Öppna PDF') }}</a>
            </div>

            @if($editable)
                <div class="mt-5 flex flex-wrap items-center gap-3" x-data="{ confirming: null }">
                    @if($isQuote)
                        <x-button type="button" wire:click="toOrder" wire:loading.attr="disabled">{{ __('Kunden tackade ja – gör order') }}</x-button>
                    @endif
                    <button type="button" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:click="toInvoice" wire:loading.attr="disabled">{{ __('Gör faktura') }}</button>
                    <button type="button" class="text-sm text-gray-600 underline" x-show="confirming !== 'cancel'" x-on:click="confirming = 'cancel'">{{ __('Makulera') }}</button>
                    <span x-show="confirming === 'cancel'" x-cloak class="flex items-center gap-3 text-sm">
                        <button type="button" class="font-semibold text-accent-700" wire:click="cancel" x-on:click="confirming = null">{{ __('Ja, makulera') }}</button>
                        <button type="button" class="text-gray-600 underline" x-on:click="confirming = null">{{ __('Avbryt') }}</button>
                    </span>
                </div>
                <x-input-error for="document" class="mt-2" />
                <x-input-error for="exchange_rate" class="mt-2" />
                <x-input-error for="currency" class="mt-2" />

                @if($expiredChoice !== null)
                    {{-- An expired quote: which prices apply to the order or invoice (ADR-082 §6). --}}
                    <div class="mt-5 rounded-xl p-4 ring-1 ring-accent-200" role="region" aria-labelledby="expired-choice-title" tabindex="-1" x-data x-init="$nextTick(() => $el.focus())" data-expired-choice>
                        <h3 id="expired-choice-title" class="text-sm font-semibold text-gray-900">{{ __('Offerten gällde till :date. Vilka priser ska gälla?', ['date' => $document->until_date?->toDateString()]) }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ __('Kunden tackade ja till offertens priser. Du kan också hämta dagens priser; priser som är skrivna för hand ändras inte.') }}</p>

                        @if($todaysDiff !== null)
                            @if($todaysDiff === [])
                                <p class="mt-3 text-sm text-gray-800">{{ __('Dagens priser är desamma som offertens.') }}</p>
                            @else
                                <div class="mt-3 overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-gray-500">
                                                <th class="pb-1 pr-3 font-medium">{{ __('Rad') }}</th>
                                                <th class="pb-1 pr-3 font-medium">{{ __('Benämning') }}</th>
                                                <th class="pb-1 pr-3 text-right font-medium">{{ __('Offertens pris') }}</th>
                                                <th class="pb-1 pr-3 text-right font-medium">{{ __('Dagens pris') }}</th>
                                                <th class="pb-1 font-medium">{{ __('Skäl') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($todaysDiff as $change)
                                                <tr class="border-t border-gray-100">
                                                    <td class="py-1 pr-3 tabular-nums">{{ $change['line'] }}</td>
                                                    <td class="py-1 pr-3">{{ $change['description'] }}</td>
                                                    <td class="py-1 pr-3 text-right tabular-nums whitespace-nowrap">{{ $change['old'] }}</td>
                                                    <td class="py-1 pr-3 text-right font-semibold tabular-nums whitespace-nowrap">{{ $change['new'] }}</td>
                                                    <td class="py-1">{{ $change['source'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <x-button type="button" wire:click="{{ $expiredChoice === 'order' ? 'toOrder' : 'toInvoice' }}('keep')" wire:loading.attr="disabled">{{ __('Behåll offertens priser') }}</x-button>
                            @if($todaysDiff === null)
                                <button type="button" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:click="previewTodaysPrices" wire:loading.attr="disabled">{{ __('Hämta dagens priser') }}</button>
                            @else
                                <button type="button" class="inline-flex items-center px-4 py-2 max-sm:min-h-11 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:click="{{ $expiredChoice === 'order' ? 'toOrder' : 'toInvoice' }}('today')" wire:loading.attr="disabled">{{ $expiredChoice === 'order' ? __('Gör order med dagens priser') : __('Gör faktura med dagens priser') }}</button>
                            @endif
                            <button type="button" class="text-sm text-gray-600 underline" wire:click="closeExpiredChoice">{{ __('Avbryt') }}</button>
                        </div>
                    </div>
                @endif
            @endif

            @if($canSend)
                <form wire:submit="send" class="mt-5 flex flex-wrap items-start gap-3 border-t border-gray-100 pt-5">
                    <div class="min-w-[16rem] flex-1">
                        <label for="document_email" class="sr-only">{{ __('E-post') }}</label>
                        <input id="document_email" type="email" class="{{ $input }}" wire:model="email" placeholder="{{ __('kund@exempel.se') }}">
                        <x-input-error for="email" class="mt-1" />
                    </div>
                    <x-button wire:loading.attr="disabled">{{ $isQuote ? __('Skicka offerten') : __('Skicka orderbekräftelse') }}</x-button>
                </form>
            @endif
        </x-ui.card>
    @endif

    <fieldset @disabled(! $editable) class="space-y-6">
        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field for="document_customer" :label="__('Kund')" error="customer_id" class="sm:col-span-2">
                    @if($customers->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">{{ __('Du har inga kunder ännu.') }} <a href="{{ route('invoicing.customers', ['ny' => 1]) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Lägg till en kund') }}</a></p>
                    @else
                        <select id="document_customer" class="{{ $input }} mt-1" wire:model="customerId">
                            <option value="">{{ __('Välj kund…') }}</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->number }} {{ $customer->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </x-ui.field>
                <x-ui.field for="document_date" :label="__('Datum')" error="document_date">
                    <input id="document_date" type="date" class="{{ $input }} mt-1" wire:model.change="documentDate">
                </x-ui.field>
                <x-ui.field for="document_currency" :label="__('Valuta')" error="currency" :hint="$currency === 'SEK' ? __('Välj en annan valuta för att skriva priserna i den.') : __('Priserna skrivs i :currency. Valutan följer med till ordern och fakturan.', ['currency' => $currency])">
                    <select id="document_currency" class="{{ $input }} mt-1" wire:model.change="currency">
                        @foreach($currencies as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="document_until" :label="$isQuote ? __('Giltig till') : __('Leveransdatum')" error="until_date">
                    <input id="document_until" type="date" class="{{ $input }} mt-1" wire:model="untilDate">
                </x-ui.field>
                <x-ui.field for="document_your_reference" :label="__('Er referens')" error="your_reference">
                    <input id="document_your_reference" type="text" class="{{ $input }} mt-1" wire:model="yourReference">
                </x-ui.field>
                <x-ui.field for="document_our_reference" :label="__('Vår referens')" error="our_reference">
                    <input id="document_our_reference" type="text" class="{{ $input }} mt-1" wire:model="ourReference">
                </x-ui.field>
            </div>
        </x-ui.card>

        @include('livewire.invoicing.partials.rows', ['negative' => false, 'withCustomer' => true, 'withCurrency' => true, 'priceWatch' => ['customerId', 'documentDate', 'currency']])

        <x-ui.card>
            <x-ui.field for="document_notes" :label="__('Meddelande (frivilligt)')" error="notes">
                <textarea id="document_notes" rows="3" class="{{ $input }} mt-1" wire:model="notes" maxlength="2000"></textarea>
            </x-ui.field>
        </x-ui.card>
    </fieldset>

    @if($editable)
        <div class="flex flex-wrap items-center justify-end gap-3">
            <a href="{{ route($routeBase) }}" class="text-sm text-gray-600 underline">{{ __('Tillbaka') }}</a>
            <x-button type="button" wire:click="save" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
        </div>
    @endif
</div>
