{{-- A supplier invoice: from a Peppol file, read by NONOX AI, or written by hand. --}}
<div class="mt-4 space-y-3">
    <div class="grid gap-3 sm:grid-cols-2">
        <x-ui.field for="invoice_supplier" :label="__('Leverantör')" error="supplier_id">
            <select id="invoice_supplier" class="{{ $cell }} mt-1" wire:model.live="form.invoice.supplier_id">
                <option value="">{{ __('Välj leverantör') }}</option>
                @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
            </select>
        </x-ui.field>
        @if(empty($form['invoice']['supplier_id']) && ($form['invoice']['supplier_name'] ?? '') !== '')
            <div class="pt-6 text-sm">
                <button type="button" wire:click="createSupplier" class="font-medium text-accent-700 hover:underline">{{ __('Lägg till :name i leverantörsregistret', ['name' => $form['invoice']['supplier_name']]) }}</button>
            </div>
        @endif
        <x-ui.field for="invoice_number" :label="__('Fakturanummer')" error="invoice_number"><input id="invoice_number" type="text" class="{{ $cell }} mt-1" wire:model.blur="form.invoice.invoice_number"></x-ui.field>
        <x-ui.field for="invoice_ocr" :label="__('OCR')" error="ocr"><input id="invoice_ocr" type="text" inputmode="numeric" class="{{ $cell }} mt-1" wire:model.blur="form.invoice.ocr"></x-ui.field>
        <x-ui.field for="invoice_date" :label="__('Fakturadatum')" error="invoice_date"><input id="invoice_date" type="date" class="{{ $cell }} mt-1" wire:model.blur="form.invoice.invoice_date"></x-ui.field>
        <x-ui.field for="invoice_due" :label="__('Förfallodag')" error="due_date"><input id="invoice_due" type="date" class="{{ $cell }} mt-1" wire:model.blur="form.invoice.due_date"></x-ui.field>
        <x-ui.field for="invoice_total" :label="__('Att betala')" error="total"><input id="invoice_total" type="text" inputmode="decimal" class="{{ $cell }} mt-1 text-right tabular-nums" wire:model.blur="form.invoice.total"></x-ui.field>
        <x-ui.field for="invoice_vat" :label="__('Varav moms')" error="vat"><input id="invoice_vat" type="text" inputmode="decimal" class="{{ $cell }} mt-1 text-right tabular-nums" wire:model.blur="form.invoice.vat"></x-ui.field>
    </div>
    @if(($form['invoice']['bankgiro'] ?? '') !== '' || ($form['invoice']['plusgiro'] ?? '') !== '' || ($form['invoice']['iban'] ?? '') !== '')
        <p class="text-sm text-gray-600">{{ __('Betalningsuppgifter på fakturan: :details', ['details' => collect([($form['invoice']['bankgiro'] ?? '') !== '' ? 'Bankgiro '.$form['invoice']['bankgiro'] : null, ($form['invoice']['plusgiro'] ?? '') !== '' ? 'Plusgiro '.$form['invoice']['plusgiro'] : null, ($form['invoice']['iban'] ?? '') !== '' ? 'IBAN '.$form['invoice']['iban'] : null])->filter()->implode(', ')]) }}</p>
    @endif
    <table class="min-w-full text-sm">
        <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600"><tr><th class="py-1 pr-2">{{ __('Konto') }}</th><th class="py-1 pr-2">{{ __('Belopp exkl. moms') }}</th><th class="py-1 pr-2">{{ __('Text') }}</th><th></th></tr></thead>
        <tbody>
            @foreach((array) ($form['invoice']['rows'] ?? []) as $rowIndex => $row)
                <tr wire:key="invoice-row-{{ $rowIndex }}">
                    <td class="py-1 pr-2"><input type="text" list="inbox-invoice-accounts" class="{{ $cell }} w-24" wire:model.blur="form.invoice.rows.{{ $rowIndex }}.account" aria-label="{{ __('Konto') }}"></td>
                    <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} w-32 text-right tabular-nums" wire:model.blur="form.invoice.rows.{{ $rowIndex }}.amount" aria-label="{{ __('Belopp exkl. moms') }}"></td>
                    <td class="py-1 pr-2"><input type="text" maxlength="255" class="{{ $cell }}" wire:model.blur="form.invoice.rows.{{ $rowIndex }}.description" aria-label="{{ __('Text') }}"></td>
                    <td class="py-1"><button type="button" wire:click="removeRow({{ $rowIndex }})" class="text-xs text-red-700 hover:underline" aria-label="{{ __('Ta bort raden') }}">✕</button></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <datalist id="inbox-invoice-accounts">@foreach($accounts as $account)<option value="{{ $account->number }}">{{ $account->name }}</option>@endforeach</datalist>
    <button type="button" wire:click="addRow" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Lägg till rad') }}</button>
    <x-input-error for="rows" class="mt-1" />
    <x-input-error for="name" class="mt-1" />
</div>
