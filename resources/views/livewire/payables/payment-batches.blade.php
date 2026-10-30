@php
    use App\Domain\Payables\PaymentBank;
    use App\Domain\Payables\PaymentBatchStatus;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
@endphp

<div class="space-y-6">
    <x-ui.card :title="__('Bank för betalfiler')">
        @if($editingBank && $canChangeBank)
            <form wire:submit="saveBank" class="grid gap-4 sm:grid-cols-3">
                <x-ui.field for="payment_bank" :label="__('Bank')" error="payment_bank">
                    <select id="payment_bank" class="{{ $input }} mt-1" wire:model.live="bank.payment_bank">
                        <option value="">{{ __('Välj bank…') }}</option>
                        @foreach(PaymentBank::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="payment_iban" :label="__('Företagskontots IBAN')" error="iban">
                    <input id="payment_iban" type="text" class="{{ $input }} mt-1 tabular-nums" wire:model="bank.iban" placeholder="SE45 5000 0000 0583 9825 7466">
                </x-ui.field>
                <x-ui.field for="payment_agreement" :label="__('Avtals-id för filöverföring')" error="bank_agreement_id" :hint="PaymentBank::tryFrom($bank['payment_bank'])?->agreementHint() ?? __('Får du av banken när filöverföring kopplas på.')">
                    <input id="payment_agreement" type="text" class="{{ $input }} mt-1" wire:model="bank.bank_agreement_id">
                </x-ui.field>
                <div class="sm:col-span-3 flex items-center gap-4">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    @if($company->payment_bank)
                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('editingBank', false)">{{ __('Avbryt') }}</button>
                    @endif
                </div>
            </form>
            <p class="mt-4 text-sm text-gray-600">{{ __('Betalfiler kan i dag göras för Danske Bank, Handelsbanken, Länsförsäkringar, Nordea och SEB. Swedbank och sparbankerna kommer. Skicka en första fil till bankens testtjänst innan du betalar på riktigt.') }}</p>
        @elseif($company->payment_bank)
            <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
                <p class="text-gray-700">{{ __(':bank, konto :iban, avtals-id :agreement', ['bank' => $company->payment_bank->label(), 'iban' => $company->iban, 'agreement' => $company->bank_agreement_id]) }}</p>
                @if($canChangeBank)
                    <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="$set('editingBank', true)">{{ __('Ändra') }}</button>
                @endif
            </div>
        @else
            <p class="text-sm text-gray-700">{{ __('Ingen bank är vald ännu. En ägare eller administratör fyller i den här.') }}</p>
        @endif
    </x-ui.card>

    @if($canCreate && $company->payment_bank)
        <x-ui.card :title="__('Att betala')" :description="__('Attesterade fakturor som inte redan finns i en betalfil. Fakturor som förfaller inom en vecka är förvalda.')">
            @if($payable->isEmpty())
                <x-ui.empty-state :title="__('Inget att betala')">{{ __('När en leverantörsfaktura är attesterad visas den här.') }}</x-ui.empty-state>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="py-2 pr-3 w-8"><span class="sr-only">{{ __('Välj') }}</span></th>
                                <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Leverantör') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Betala till') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Förfaller') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Belopp') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($payable as $invoice)
                                @php($snapshot = $invoice->supplier_snapshot ?? [])
                                <tr wire:key="payable-{{ $invoice->id }}">
                                    <td class="py-2 pr-3"><input type="checkbox" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" value="{{ $invoice->id }}" wire:model.live="selected" aria-label="{{ __('Välj :title', ['title' => $invoice->title()]) }}"></td>
                                    <td class="py-2 pr-4"><a href="{{ route('payables.invoices.show', $invoice) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $invoice->number }}</a></td>
                                    <td class="py-2 pr-4">{{ $invoice->supplierName() }}</td>
                                    <td class="py-2 pr-4 tabular-nums">
                                        @if(filled($snapshot['bankgiro'] ?? null))
                                            {{ __('Bankgiro :number', ['number' => $snapshot['bankgiro']]) }}
                                        @elseif(filled($snapshot['plusgiro'] ?? null))
                                            {{ __('Plusgiro :number', ['number' => $snapshot['plusgiro']]) }}
                                        @else
                                            <span class="text-accent-700">{{ __('Saknas') }}</span>
                                        @endif
                                        <span class="block text-xs text-gray-500">{{ $invoice->ocr ? __('OCR :ocr', ['ocr' => $invoice->ocr]) : __('Meddelande: faktura :number', ['number' => $invoice->invoice_number ?? $invoice->number]) }}</span>
                                    </td>
                                    <td @class(['py-2 pr-4 tabular-nums', 'text-accent-700' => $invoice->isOverdue()])>{{ $invoice->due_date->toDateString() }}</td>
                                    <td class="py-2 text-right"><x-ui.amount :money="$invoice->remaining()" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <form wire:submit="create" class="mt-5 flex flex-wrap items-end justify-between gap-4">
                    <x-ui.field for="execution_date" :label="__('Betaldag')" error="execution_date">
                        <input id="execution_date" type="date" class="{{ $input }} mt-1" wire:model="executionDate" min="{{ now()->toDateString() }}">
                    </x-ui.field>
                    <div class="flex items-center gap-4">
                        <p class="text-sm">{{ count($selected) === 1 ? __('1 faktura vald,') : __(':count fakturor valda,', ['count' => count($selected)]) }} <x-ui.amount :money="Money::ofOre($selectedTotal)" class="font-semibold" /></p>
                        <x-button wire:loading.attr="disabled" :disabled="$selected === []">{{ __('Skapa betalfil') }}</x-button>
                    </div>
                </form>
                @error('batch') <p class="mt-2 text-sm text-accent-700">{{ $message }}</p> @enderror
                @foreach($errors->get('batch.*') as $messages)
                    @foreach((array) $messages as $message)
                        <p class="mt-1 text-sm text-accent-700">{{ $message }}</p>
                    @endforeach
                @endforeach
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Betalfiler')">
        @if($batches->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga betalfiler ännu.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($batches as $batch)
                    <li wire:key="batch-{{ $batch->id }}" class="flex flex-wrap items-center gap-4 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-gray-900">{{ $batch->title() }} · {{ $batch->bank->label() }}</span>
                            <span class="block text-gray-600">{{ __('Betaldag :date, :count betalningar, skapad av :name', ['date' => $batch->execution_date->toDateString(), 'count' => $batch->payment_count, 'name' => $batch->creator?->name]) }}</span>
                        </span>
                        <x-ui.amount :money="$batch->total_ore" class="font-medium" />
                        <x-ui.badge :tone="$batch->status === PaymentBatchStatus::Confirmed ? 'success' : 'neutral'">{{ $batch->status->label() }}</x-ui.badge>
                        <a href="{{ route('payables.batches.file', $batch) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ned fil') }}</a>
                        @if($batch->isOpen() && $canCreate)
                            <span x-data="{ confirming: null }" class="flex items-center gap-3">
                                <button type="button" class="font-medium text-accent-600 hover:text-accent-700" x-show="! confirming" x-on:click="confirming = 'paid'">{{ __('Banken har betalat') }}</button>
                                <button type="button" class="text-gray-600 underline" x-show="! confirming" x-on:click="confirming = 'cancel'">{{ __('Makulera') }}</button>
                                <span x-show="confirming === 'paid'" x-cloak class="flex items-center gap-2">
                                    <span class="text-gray-700">{{ __('Alla betalningar bokförs på betaldagen.') }}</span>
                                    <x-button type="button" wire:click="confirm('{{ $batch->id }}')">{{ __('Ja, bokför') }}</x-button>
                                    <button type="button" class="text-gray-600 underline" x-on:click="confirming = null">{{ __('Avbryt') }}</button>
                                </span>
                                <span x-show="confirming === 'cancel'" x-cloak class="flex items-center gap-2">
                                    <span class="text-gray-700">{{ __('Gör det bara om banken inte betalade filen.') }}</span>
                                    <button type="button" class="font-medium text-accent-700 underline" wire:click="cancel('{{ $batch->id }}')">{{ __('Ja, makulera') }}</button>
                                    <button type="button" class="text-gray-600 underline" x-on:click="confirming = null">{{ __('Avbryt') }}</button>
                                </span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
