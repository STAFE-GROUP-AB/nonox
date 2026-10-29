@php
    use App\Support\Money\Money;
    $debit = Money::ofOre($voucher->rows->sum(fn ($row) => $row->debit_ore->ore));
    $credit = Money::ofOre($voucher->rows->sum(fn ($row) => $row->credit_ore->ore));
@endphp

<x-app-layout>
    <div class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
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
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Radtext') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium text-right">{{ __('Debet') }}</th>
                            <th scope="col" class="py-2 font-medium text-right">{{ __('Kredit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($voucher->rows as $row)
                            <tr>
                                <td class="py-2.5 pr-4"><span class="font-semibold tabular-nums">{{ $row->account?->number }}</span> {{ $row->account?->name }}</td>
                                <td class="py-2.5 pr-4 text-gray-600">
                                    {{ $row->description }}
                                    @if($row->costCenter || $row->project)
                                        <span class="block text-xs text-gray-500">
                                            @if($row->costCenter){{ __('Kostnadsställe') }}: {{ $row->costCenter->label() }}@endif
                                            @if($row->costCenter && $row->project) · @endif
                                            @if($row->project){{ __('Projekt') }}: {{ $row->project->label() }}@endif
                                        </span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4 text-right">@unless($row->debit_ore->isZero())<x-ui.amount :money="$row->debit_ore" :currency="false" />@endunless</td>
                                <td class="py-2.5 text-right">@unless($row->credit_ore->isZero())<x-ui.amount :money="$row->credit_ore" :currency="false" />@endunless</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200 font-semibold">
                            <td class="pt-3 pr-4" colspan="2">{{ __('Summa') }}</td>
                            <td class="pt-3 pr-4 text-right"><x-ui.amount :money="$debit" :currency="false" /></td>
                            <td class="pt-3 text-right"><x-ui.amount :money="$credit" :currency="false" /></td>
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
