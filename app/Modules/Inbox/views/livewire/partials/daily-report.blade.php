{{-- A daily report: each amount on the report, what it is and its account. The voucher below is built from this, never typed. --}}
@php($fields = (array) ($message->proposal['fields'] ?? []))
<div class="mt-4 space-y-3">
    <x-ui.field for="report_date" :label="__('Rapportens datum')" class="max-w-xs">
        <input id="report_date" type="date" class="{{ $input }} mt-1" wire:model.blur="form.date">
    </x-ui.field>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                <tr><th class="py-1 pr-2">{{ __('På rapporten') }}</th><th class="py-1 pr-2 text-right">{{ __('Belopp') }}</th><th class="py-1 pr-2">{{ __('Vad det är') }}</th><th class="py-1 pr-2">{{ __('Moms %') }}</th><th class="py-1 pr-2">{{ __('Konto') }}</th><th class="py-1">{{ __('D/K') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach((array) ($form['mapping'] ?? []) as $index => $entry)
                    @php($field = $fields[$entry['key']] ?? ['label' => $entry['key'], 'amount_ore' => 0])
                    <tr wire:key="field-{{ md5($entry['key']) }}">
                        <td class="py-1 pr-2">
                            {{ $field['label'] }}
                            @if(($form['mapping'][$index]['reason'] ?? '') !== '')
                                <span class="block text-xs text-gray-500">{{ $form['mapping'][$index]['reason'] }}@if(isset($form['mapping'][$index]['confidence'])) · {{ __('säkerhet :percent %', ['percent' => (int) round(((float) $form['mapping'][$index]['confidence']) * 100)]) }}@endif</span>
                            @endif
                        </td>
                        <td class="py-1 pr-2 text-right tabular-nums">{{ App\Support\Money\Money::ofOre((int) $field['amount_ore'])->format() }}</td>
                        <td class="py-1 pr-2">
                            <select class="{{ $cell }}" wire:model.live="form.mapping.{{ $index }}.role" aria-label="{{ __('Vad det är') }}">
                                @foreach($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach
                            </select>
                        </td>
                        <td class="py-1 pr-2">
                            <select class="{{ $cell }}" wire:model.live="form.mapping.{{ $index }}.vat_rate" aria-label="{{ __('Moms %') }}">
                                <option value="">–</option>
                                @foreach(App\Modules\Inbox\Support\DailyReportVoucher::VAT_RATES as $rate)<option value="{{ $rate }}">{{ $rate }}</option>@endforeach
                            </select>
                        </td>
                        <td class="py-1 pr-2"><input type="text" list="inbox-accounts" class="{{ $cell }} w-24" wire:model.live.debounce.500ms="form.mapping.{{ $index }}.account" aria-label="{{ __('Konto') }}"></td>
                        <td class="py-1">
                            <select class="{{ $cell }}" wire:model.live="form.mapping.{{ $index }}.side" aria-label="{{ __('D/K') }}"><option value="D">D</option><option value="K">K</option></select>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <datalist id="inbox-accounts">@foreach($accounts as $account)<option value="{{ $account->number }}">{{ $account->name }}</option>@endforeach</datalist>

    @if($preview)
        <div class="rounded-lg bg-gray-50 p-3">
            <p class="text-sm font-semibold text-gray-900">{{ __('Verifikationen som bokförs') }}</p>
            <table class="mt-2 min-w-full text-sm">
                <tbody class="divide-y divide-gray-200">
                    @foreach($preview['rows'] as $row)
                        <tr><td class="py-1 pr-2 tabular-nums">{{ $row['account'] }}</td><td class="py-1 pr-2">{{ $row['description'] }}</td>
                            <td class="py-1 pr-2 text-right tabular-nums">{{ $row['debit_ore'] > 0 ? App\Support\Money\Money::ofOre($row['debit_ore'])->format(withCurrency: false) : '' }}</td>
                            <td class="py-1 text-right tabular-nums">{{ $row['credit_ore'] > 0 ? App\Support\Money\Money::ofOre($row['credit_ore'])->format(withCurrency: false) : '' }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot class="font-semibold"><tr><td colspan="2" class="py-1">{{ __('Summa') }}</td>
                    <td class="py-1 pr-2 text-right tabular-nums">{{ App\Support\Money\Money::ofOre($preview['debit'])->format(withCurrency: false) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ App\Support\Money\Money::ofOre($preview['credit'])->format(withCurrency: false) }}</td></tr></tfoot>
            </table>
            @foreach($preview['vat'] as $rate => $sums)
                <p class="mt-1 text-xs text-gray-600">{{ __('Moms :rate %: försäljning :net, moms :vat', ['rate' => $rate, 'net' => App\Support\Money\Money::ofOre($sums['net'])->format(), 'vat' => App\Support\Money\Money::ofOre($sums['vat'])->format()]) }}</p>
            @endforeach
            <p @class(['mt-2 text-sm font-semibold', 'text-green-800' => $preview['balanced'], 'text-red-700' => ! $preview['balanced']])>
                {{ $preview['balanced'] ? __('Debet och kredit är lika.') : __('Debet och kredit är inte lika. Kontrollera kopplingen.') }}
            </p>
            @foreach($preview['warnings'] as $warning)<p class="text-sm text-red-700">{{ $warning['message'] }}</p>@endforeach
        </div>
    @endif
    <x-input-error for="mapping" class="mt-1" />
</div>
