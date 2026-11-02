@php
    use App\Domain\Closing\LedgerReconciliation;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $customers = $side === LedgerReconciliation::CUSTOMERS;
@endphp

<div class="space-y-6">
    <x-ui.card class="print:hidden">
        <div class="grid gap-4 sm:grid-cols-2 sm:items-end">
            <x-ui.field for="ledger_side" :label="__('Reskontra')">
                <select id="ledger_side" class="{{ $input }} mt-1" wire:model.live="side">
                    <option value="{{ LedgerReconciliation::CUSTOMERS }}">{{ __('Kundreskontra (1510)') }}</option>
                    <option value="{{ LedgerReconciliation::SUPPLIERS }}">{{ __('Leverantörsreskontra (2440)') }}</option>
                </select>
            </x-ui.field>
            <x-ui.field for="ledger_date" :label="__('Per dag')">
                <input id="ledger_date" type="date" class="{{ $input }} mt-1" wire:model.live="date">
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card :title="$customers ? __('Obetalda kundfakturor :date', ['date' => $day->toDateString()]) : __('Obetalda leverantörsfakturor :date', ['date' => $day->toDateString()])">
        @if($report['items'] === [])
            <p class="text-sm text-gray-600">{{ __('Inga obetalda fakturor den dagen.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm tabular-nums">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="pb-2 pr-3 font-medium">{{ __('Faktura') }}</th>
                            <th class="pb-2 pr-3 font-medium">{{ $customers ? __('Kund') : __('Leverantör') }}</th>
                            <th class="pb-2 pr-3 font-medium">{{ __('Fakturadatum') }}</th>
                            <th class="pb-2 font-medium text-right">{{ __('Obetalt') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report['items'] as $item)
                            <tr class="border-t border-gray-100">
                                <td class="py-1.5 pr-3">{{ $item['title'] }}</td>
                                <td class="py-1.5 pr-3">{{ $item['name'] }}</td>
                                <td class="py-1.5 pr-3">{{ $item['date'] }}</td>
                                <td @class(['py-1.5 text-right', 'text-accent-700' => $item['open'] < 0])>{{ Money::ofOre($item['open'])->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <dl class="mt-5 max-w-sm space-y-1 text-sm tabular-nums">
            <div class="flex justify-between"><dt class="text-gray-600">{{ __('Summa reskontra') }}</dt><dd>{{ Money::ofOre($report['ledger'])->format() }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-600">{{ __('Saldo konto :account', ['account' => $report['account']]) }}</dt><dd>{{ Money::ofOre($report['balance'])->format() }}</dd></div>
            <div @class(['flex justify-between border-t border-gray-200 pt-1 font-semibold', 'text-accent-700' => $report['difference'] !== 0])><dt>{{ __('Differens') }}</dt><dd>{{ Money::ofOre($report['difference'])->format() }}</dd></div>
        </dl>

        @if($report['difference'] === 0)
            <p class="mt-3 text-sm text-gray-700" role="status">{{ __('Reskontran stämmer med kontot.') }}</p>
        @else
            <p class="mt-3 text-sm text-gray-700" role="status">{{ __('Reskontran stämmer inte med kontot. Leta efter verifikationer som är bokförda direkt på kontot, eller fakturor som inte är bokförda.') }}
                @if($company->accounting_method === App\Domain\Company\AccountingMethod::Kontantmetoden){{ __('Med kontantmetoden bokförs fakturorna på kontot först vid årets slut, så under året är en differens väntad.') }}@endif</p>
        @endif
    </x-ui.card>
</div>
