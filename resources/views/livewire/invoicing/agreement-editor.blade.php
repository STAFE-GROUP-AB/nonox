@php
    use App\Domain\Invoicing\AgreementStatus;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $intervals = [1 => __('Varje månad'), 3 => __('Varje kvartal'), 6 => __('Varje halvår'), 12 => __('Varje år')];
@endphp

<div class="space-y-6">
    @if($agreement)
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="text-sm text-gray-700">
                    {{ __('Status') }}: <span class="font-semibold">{{ $agreement->status->label() }}</span>
                    · {{ $agreement->intervalLabel() }}
                    @if($agreement->status !== AgreementStatus::Ended)
                        · {{ __('nästa faktura :date', ['date' => $agreement->next_invoice_date->toDateString()]) }}
                    @endif
                </p>
                @if($editable)
                    <div class="flex flex-wrap items-center gap-3 text-sm" x-data="{ confirming: false }">
                        @if($agreement->isDue())
                            <x-button type="button" wire:click="invoiceNow" wire:loading.attr="disabled">{{ $agreement->send_automatically ? __('Skapa och skicka fakturan nu') : __('Skapa fakturan nu') }}</x-button>
                        @endif
                        @if($agreement->status === AgreementStatus::Active)
                            <button type="button" class="font-medium text-gray-700 underline" wire:click="setStatus('PAUSED')">{{ __('Pausa') }}</button>
                        @elseif($agreement->status === AgreementStatus::Paused)
                            <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="setStatus('ACTIVE')">{{ __('Aktivera') }}</button>
                        @endif
                        @if($agreement->status !== AgreementStatus::Ended)
                            <button type="button" class="text-gray-600 underline" x-show="! confirming" x-on:click="confirming = true">{{ __('Avsluta avtalet') }}</button>
                            <span x-show="confirming" x-cloak class="flex items-center gap-2">
                                <span class="text-gray-700">{{ __('Inga fler fakturor skapas.') }}</span>
                                <button type="button" class="font-medium text-accent-700 underline" wire:click="setStatus('ENDED')">{{ __('Ja, avsluta') }}</button>
                                <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                            </span>
                        @endif
                    </div>
                @endif
            </div>
            <x-input-error for="agreement" class="mt-2" />
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="agreement_name" :label="__('Namn på avtalet')" error="name" class="sm:col-span-2">
                <input id="agreement_name" type="text" class="{{ $input }} mt-1" wire:model="name" maxlength="255" placeholder="{{ __('Till exempel Lunchavtal kontoret') }}" @disabled(! $editable)>
            </x-ui.field>
            <x-ui.field for="agreement_customer" :label="__('Kund')" error="customer_id" class="sm:col-span-2">
                <select id="agreement_customer" class="{{ $input }} mt-1" wire:model="customerId" @disabled(! $editable)>
                    <option value="">{{ __('Välj kund…') }}</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->number }} {{ $customer->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="agreement_interval" :label="__('Hur ofta')" error="interval_months">
                <select id="agreement_interval" class="{{ $input }} mt-1" wire:model="intervalMonths" @disabled(! $editable)>
                    @foreach($intervals as $months => $label)
                        <option value="{{ $months }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="agreement_next" :label="__('Nästa fakturadatum')" error="next_invoice_date" :hint="__('Fakturorna dateras samma dag i månaden.')">
                <input id="agreement_next" type="date" class="{{ $input }} mt-1" wire:model="nextInvoiceDate" @disabled(! $editable)>
            </x-ui.field>
            <x-ui.field for="agreement_end" :label="__('Sista dag (frivilligt)')" error="end_date" :hint="__('Tomt: tills vidare.')">
                <input id="agreement_end" type="date" class="{{ $input }} mt-1" wire:model="endDate" @disabled(! $editable)>
            </x-ui.field>
            @if($tracksRecurringRevenue && $editable)
                <x-ui.field for="agreement_effective_from" :label="__('Gäller från')" error="effective_from" :hint="__('För Återkommande intäkter: från vilket datum en ändring, paus eller avslutning gäller. Tomt: i dag.')">
                    <input id="agreement_effective_from" type="date" class="{{ $input }} mt-1" wire:model="effectiveFrom">
                </x-ui.field>
            @endif
            <x-ui.field for="agreement_your_reference" :label="__('Er referens')" error="your_reference">
                <input id="agreement_your_reference" type="text" class="{{ $input }} mt-1" wire:model="yourReference" @disabled(! $editable)>
            </x-ui.field>
            <div class="sm:col-span-2 lg:col-span-4">
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" class="mt-0.5 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="sendAutomatically" @disabled(! $editable)>
                    <span>
                        <span class="font-medium text-gray-900">{{ __('Skapa, bokför och skicka fakturan automatiskt') }}</span>
                        <span class="block text-gray-600">{{ __('Annars blir varje faktura ett utkast som du kontrollerar och skickar själv. Kunden behöver en e-postadress.') }}</span>
                    </span>
                </label>
                <x-input-error for="send_automatically" class="mt-1" />
            </div>
        </div>
    </x-ui.card>

    @include('livewire.invoicing.partials.rows', ['negative' => false])

    <x-ui.card>
        <x-ui.field for="agreement_notes" :label="__('Meddelande på fakturorna (frivilligt)')" error="notes">
            <textarea id="agreement_notes" rows="3" class="{{ $input }} mt-1" wire:model="notes" maxlength="2000" @disabled(! $editable)></textarea>
        </x-ui.field>
    </x-ui.card>

    @if($editable)
        <div class="flex flex-wrap items-center justify-end gap-3">
            <a href="{{ route('invoicing.agreements') }}" class="text-sm text-gray-600 underline">{{ __('Tillbaka') }}</a>
            <x-button type="button" wire:click="save" wire:loading.attr="disabled">{{ __('Spara avtalet') }}</x-button>
        </div>
    @endif

    @if($agreement && $agreement->invoices->isNotEmpty())
        <x-ui.card :title="__('Fakturor från avtalet')">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($agreement->invoices as $invoice)
                    <li class="flex justify-between gap-4 py-2">
                        <a href="{{ $invoice->isDraft() ? route('invoicing.invoices.edit', $invoice) : route('invoicing.invoices.show', $invoice) }}" class="text-accent-600 hover:text-accent-700">{{ $invoice->title() }}</a>
                        <span class="tabular-nums text-gray-600">{{ $invoice->invoice_date->toDateString() }}</span>
                        <x-ui.amount :money="$invoice->total_ore" />
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
