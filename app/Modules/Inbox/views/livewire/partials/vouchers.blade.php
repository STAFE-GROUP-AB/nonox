{{-- One or more vouchers: from a SIE file, read by NONOX AI from a receipt, or written by hand. --}}
<div class="mt-4 space-y-4">
    @foreach((array) ($form['vouchers'] ?? []) as $index => $voucher)
        <div class="rounded-lg border border-gray-200 p-3" wire:key="voucher-{{ $index }}">
            <div class="grid gap-2 sm:grid-cols-[10rem_1fr]">
                <x-ui.field for="voucher_date_{{ $index }}" :label="__('Datum')"><input id="voucher_date_{{ $index }}" type="date" class="{{ $cell }} mt-1" wire:model.blur="form.vouchers.{{ $index }}.date"></x-ui.field>
                <x-ui.field for="voucher_text_{{ $index }}" :label="__('Beskrivning')"><input id="voucher_text_{{ $index }}" type="text" maxlength="255" class="{{ $cell }} mt-1" wire:model.blur="form.vouchers.{{ $index }}.description"></x-ui.field>
            </div>
            <table class="mt-2 min-w-full text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600"><tr><th class="py-1 pr-2">{{ __('Konto') }}</th><th class="py-1 pr-2">{{ __('Debet') }}</th><th class="py-1 pr-2">{{ __('Kredit') }}</th><th class="py-1 pr-2">{{ __('Text') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach((array) ($voucher['rows'] ?? []) as $rowIndex => $row)
                        <tr wire:key="voucher-{{ $index }}-row-{{ $rowIndex }}">
                            <td class="py-1 pr-2"><input type="text" list="inbox-voucher-accounts" class="{{ $cell }} w-24" wire:model.blur="form.vouchers.{{ $index }}.rows.{{ $rowIndex }}.account" aria-label="{{ __('Konto') }}"></td>
                            <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} w-28 text-right tabular-nums" wire:model.blur="form.vouchers.{{ $index }}.rows.{{ $rowIndex }}.debit" aria-label="{{ __('Debet') }}"></td>
                            <td class="py-1 pr-2"><input type="text" inputmode="decimal" class="{{ $cell }} w-28 text-right tabular-nums" wire:model.blur="form.vouchers.{{ $index }}.rows.{{ $rowIndex }}.credit" aria-label="{{ __('Kredit') }}"></td>
                            <td class="py-1 pr-2"><input type="text" maxlength="255" class="{{ $cell }}" wire:model.blur="form.vouchers.{{ $index }}.rows.{{ $rowIndex }}.description" aria-label="{{ __('Text') }}"></td>
                            <td class="py-1"><button type="button" wire:click="removeRow({{ $rowIndex }}, {{ $index }})" class="text-xs text-red-700 hover:underline" aria-label="{{ __('Ta bort raden') }}">✕</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <button type="button" wire:click="addRow({{ $index }})" class="mt-2 text-sm font-medium text-accent-700 hover:underline">{{ __('Lägg till rad') }}</button>
        </div>
    @endforeach
    <datalist id="inbox-voucher-accounts">@foreach($accounts as $account)<option value="{{ $account->number }}">{{ $account->name }}</option>@endforeach</datalist>
    <x-input-error for="rows" class="mt-1" />
    <x-input-error for="voucher_date" class="mt-1" />
    <x-input-error for="description" class="mt-1" />
</div>
