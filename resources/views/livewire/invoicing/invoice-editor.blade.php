@php
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $cell = 'w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-md text-sm';
    $rates = [2500 => '25 %', 1200 => '12 %', 600 => '6 %', 0 => __('0 %')];
    $kr = fn (int $ore): Money => Money::ofOre($ore);
@endphp

<div class="space-y-6">

    @if($errors->has('invoice'))
        <div class="rounded-xl border border-accent-200 bg-accent-50 px-5 py-4 text-sm text-accent-800" role="alert">{{ $errors->first('invoice') }}</div>
    @endif

    @if($credit)
        <p class="rounded-lg bg-gray-100 px-4 py-3 text-sm text-gray-700">{{ __('Det här är en kreditfaktura. Beloppen dras av från kundens faktura när den skapas.') }}</p>
    @endif

    <x-ui.card>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="invoice_customer" :label="__('Kund')" error="customer_id" class="sm:col-span-2">
                @if($customers->isEmpty())
                    <p class="mt-2 text-sm text-gray-600">{{ __('Du har inga kunder ännu.') }} <a href="{{ route('invoicing.customers') }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Lägg till en kund') }}</a></p>
                @else
                    <select id="invoice_customer" class="{{ $input }} mt-1" wire:model="customerId" @disabled($credit)>
                        <option value="">{{ __('Välj kund…') }}</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->number }} {{ $customer->name }}</option>
                        @endforeach
                    </select>
                @endif
            </x-ui.field>
            <x-ui.field for="invoice_date" :label="__('Fakturadatum')" error="invoice_date">
                <input id="invoice_date" type="date" class="{{ $input }} mt-1" wire:model="invoiceDate">
            </x-ui.field>
            @unless($credit)
                <x-ui.field for="invoice_due" :label="__('Förfallodag')" error="due_date" :hint="__('Tomt: kundens betalningsvillkor.')">
                    <input id="invoice_due" type="date" class="{{ $input }} mt-1" wire:model="dueDate">
                </x-ui.field>
            @endunless
            <x-ui.field for="invoice_delivery" :label="__('Leveransdatum')" error="delivery_date" :hint="__('Tomt: samma som fakturadatum.')">
                <input id="invoice_delivery" type="date" class="{{ $input }} mt-1" wire:model="deliveryDate">
            </x-ui.field>
            <x-ui.field for="invoice_your_reference" :label="__('Er referens')" error="your_reference">
                <input id="invoice_your_reference" type="text" class="{{ $input }} mt-1" wire:model="yourReference">
            </x-ui.field>
            <x-ui.field for="invoice_our_reference" :label="__('Vår referens')" error="our_reference">
                <input id="invoice_our_reference" type="text" class="{{ $input }} mt-1" wire:model="ourReference">
            </x-ui.field>
        </div>
    </x-ui.card>

    @include('livewire.invoicing.partials.rows', ['negative' => $credit])

    <x-ui.card>
        <x-ui.field for="invoice_notes" :label="__('Meddelande på fakturan (frivilligt)')" error="notes">
            <textarea id="invoice_notes" rows="3" class="{{ $input }} mt-1" wire:model="notes" maxlength="2000"></textarea>
        </x-ui.field>
    </x-ui.card>

    <div class="flex flex-wrap items-center justify-end gap-3" x-data="{ confirming: false }">
        <a href="{{ route('invoicing.invoices.index') }}" class="text-sm text-gray-600 underline">{{ __('Avbryt') }}</a>
        <button type="button" class="inline-flex items-center px-4 py-2 rounded-lg border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50" wire:click="save" wire:loading.attr="disabled">{{ __('Spara som utkast') }}</button>
        @if($canIssue)
            <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ $credit ? __('Skapa kreditfakturan') : __('Skapa fakturan') }}</x-button>
            <span x-show="confirming" x-cloak class="flex flex-wrap items-center gap-3 text-sm">
                <span class="text-gray-700">{{ __('Fakturan får ett nummer, bokförs och kan sedan inte ändras, bara krediteras.') }}</span>
                <x-button type="button" wire:click="issue" x-on:click="confirming = false" wire:loading.attr="disabled">{{ __('Ja, skapa') }}</x-button>
                <button type="button" class="text-gray-600 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
            </span>
        @endif
    </div>
</div>
