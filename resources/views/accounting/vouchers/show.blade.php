@php
    use App\Domain\Accounting\AccountBalances;
    use App\Support\Money\Money;
    $debit = Money::ofOre($voucher->rows->sum(fn ($row) => $row->debit_ore->ore));
    $credit = Money::ofOre($voucher->rows->sum(fn ($row) => $row->credit_ore->ore));
@endphp

<x-app-layout>
    <div class="w-full py-6 px-4 sm:px-6 lg:px-10 space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <x-ui.page-heading
                :title="$voucher->isPosted() ? __('Verifikation :reference', ['reference' => $voucher->reference()]) : __('Utkast')"
                :lead="$voucher->description" />
            <a href="{{ route('accounting.vouchers.index') }}" class="text-sm text-gray-600 underline">{{ __('Tillbaka till listan') }}</a>
        </div>

        @if($voucher->correctedBy)
            <div class="rounded-xl border border-gray-200 bg-white px-5 py-4 text-sm text-gray-700">
                {{ __('Den här verifikationen är rättad med') }}
                <a href="{{ route('accounting.vouchers.show', $voucher->correctedBy) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $voucher->correctedBy->reference() }}</a>.
            </div>
        @endif

        @if($voucher->corrects)
            <div class="rounded-xl border border-gray-200 bg-white px-5 py-4 text-sm text-gray-700">
                {{ __('Det här är en rättelse av') }}
                <a href="{{ route('accounting.vouchers.show', $voucher->corrects) }}" class="font-semibold text-accent-600 hover:text-accent-700">{{ $voucher->corrects->reference() }}</a>.
            </div>
        @endif

        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_26rem] lg:items-start">
        <div class="space-y-8">
        <x-ui.card>
            <dl class="grid gap-x-8 gap-y-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-gray-500">{{ __('Datum') }}</dt>
                    <dd class="font-medium text-gray-900 tabular-nums">{{ $voucher->voucher_date->toDateString() }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Serie') }}</dt>
                    <dd class="font-medium text-gray-900">{{ $voucher->series?->code }} – {{ __($voucher->series?->name ?? '') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">{{ __('Status') }}</dt>
                    <dd class="font-medium text-gray-900">
                        @if($voucher->isPosted())
                            {{ __('Bokförd :date av :name', ['date' => $voucher->posted_at?->format('Y-m-d H:i'), 'name' => $postedBy?->name ?? '–']) }}
                        @else
                            {{ __('Utkast, inte bokförd') }}
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Konto') }}</th>
                            @if($showCostCenters)
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kostnadsställe') }}</th>
                            @endif
                            @if($showProjects)
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Projekt') }}</th>
                            @endif
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Radtext') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium text-right">{{ __('Debet') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium text-right">{{ __('Kredit') }}</th>
                            <th scope="col" class="py-2 font-medium text-right" title="{{ __('Kontots saldo efter raden, på kontots normala sida: tillgångar och kostnader som debet, eget kapital, skulder och intäkter som kredit.') }}">{{ __('Kontosaldo') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($voucher->rows as $row)
                            <tr>
                                <td class="py-2.5 pr-4">
                                    @if($row->account)
                                        <a href="{{ route('accounting.reports', ['rapport' => 'huvudbok', 'ar' => $voucher->fiscal_year_id, 'konto' => $row->account->number]) }}" class="hover:text-accent-700" title="{{ __('Visa kontot i huvudboken') }}"><span class="font-semibold tabular-nums">{{ $row->account->number }}</span> {{ $row->account->name }}</a>
                                    @endif
                                </td>
                                @if($showCostCenters)
                                    <td class="py-2.5 pr-4 text-gray-600">{{ $row->costCenter?->label() }}</td>
                                @endif
                                @if($showProjects)
                                    <td class="py-2.5 pr-4 text-gray-600">{{ $row->project?->label() }}</td>
                                @endif
                                <td class="py-2.5 pr-4 text-gray-600">{{ $row->description }}</td>
                                <td class="py-2.5 pr-4 text-right">@unless($row->debit_ore->isZero())<x-ui.amount :money="$row->debit_ore" :currency="false" />@endunless</td>
                                <td class="py-2.5 pr-4 text-right">@unless($row->credit_ore->isZero())<x-ui.amount :money="$row->credit_ore" :currency="false" />@endunless</td>
                                <td class="py-2.5 text-right text-gray-600" data-account-balance>@if($row->account && isset($balances[$row->id]))<x-ui.amount :money="Money::ofOre(AccountBalances::forDisplay($balances[$row->id], $row->account->type))" :currency="false" />@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200 font-semibold">
                            <td class="pt-3 pr-4" colspan="{{ 2 + ($showCostCenters ? 1 : 0) + ($showProjects ? 1 : 0) }}">{{ __('Summa') }}</td>
                            <td class="pt-3 pr-4 text-right"><x-ui.amount :money="$debit" :currency="false" /></td>
                            <td class="pt-3 pr-4 text-right"><x-ui.amount :money="$credit" :currency="false" /></td>
                            <td class="pt-3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if($voucher->isPosted())
                <p class="mt-6 text-xs text-gray-500 break-all">
                    {{ __('Kontrollsumma') }}: <span class="font-mono">{{ $voucher->hash }}</span>
                </p>
            @endif
        </x-ui.card>

        <livewire:accounting.voucher-actions :voucher="$voucher" />
        </div>

        <livewire:accounting.voucher-attachments :voucher="$voucher" />
        </div>
    </div>
</x-app-layout>
