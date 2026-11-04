@php
    use App\Domain\Accounting\AccountBalances;
    use App\Support\Money\Money;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $m = fn (int $ore): Money => Money::ofOre($ore);
    $tabs = [
        'saldobalans' => __('Saldobalans'),
        'resultat' => __('Resultatrapport'),
        'balans' => __('Balansrapport'),
        'huvudbok' => __('Huvudbok'),
        'verifikationer' => __('Verifikationslista'),
    ];
    $th = 'py-2 pr-4 font-medium';
    // On a phone the account column stays in place while the amounts scroll sideways.
    $sticky = 'sticky left-0 z-[1] bg-white max-sm:min-w-40';
    $num = 'py-2 pr-4 text-right';
    // Every account leads to its Huvudbok (Kontoanalys) with the same year, period and filters, and from there to the vouchers.
    $ledgerUrl = fn (int $number): string => route('accounting.reports', $filters->accountLedgerQuery($number));
    $accountLink = 'hover:text-accent-700 hover:underline print:no-underline';
    $kontoanalys = $filters->report === 'huvudbok' && $filters->account !== null;
    $listCostCenters = $costCenters->isNotEmpty();
    $listProjects = $projects->isNotEmpty();
@endphp

<div class="space-y-6">
    <livewire:accounting.stale-opening-balances-notice :fiscal-year-id="$filters->fiscalYear->id" />

    <nav class="flex flex-wrap gap-2 print:hidden" aria-label="{{ __('Rapporter') }}">
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="show('{{ $key }}')" @class([
                'rounded-full px-4 py-1.5 text-sm font-medium',
                'bg-gray-900 text-white' => $filters->report === $key,
                'bg-white border border-gray-200 text-gray-700 hover:border-gray-300' => $filters->report !== $key,
            ]) @if($filters->report === $key) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    <x-ui.card class="print:hidden">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.field for="report_year" :label="__('Räkenskapsår')">
                <select id="report_year" class="{{ $input }} mt-1" wire:model.live="ar">
                    @foreach($fiscalYears as $year)
                        <option value="{{ $year->id }}" @selected($year->id === $filters->fiscalYear->id)>{{ $year->label() }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="report_from" :label="__('Från och med')">
                <input id="report_from" type="date" class="{{ $input }} mt-1" wire:model.live.blur="fran" value="{{ $filters->from->toDateString() }}" min="{{ $filters->fiscalYear->start_date->toDateString() }}" max="{{ $filters->fiscalYear->end_date->toDateString() }}">
            </x-ui.field>
            <x-ui.field for="report_to" :label="__('Till och med')">
                <input id="report_to" type="date" class="{{ $input }} mt-1" wire:model.live.blur="till" value="{{ $filters->to->toDateString() }}" min="{{ $filters->fiscalYear->start_date->toDateString() }}" max="{{ $filters->fiscalYear->end_date->toDateString() }}">
            </x-ui.field>
            @if($filters->report === 'huvudbok')
                <x-ui.field for="report_account" :label="__('Bara ett konto (frivilligt)')">
                    <input id="report_account" type="text" inputmode="numeric" maxlength="4" class="{{ $input }} mt-1" wire:model.live.debounce.500ms="konto" placeholder="1930">
                </x-ui.field>
            @endif
            @if($costCenters->isNotEmpty())
                <x-ui.field for="report_cost_center" :label="__('Kostnadsställe')">
                    <select id="report_cost_center" class="{{ $input }} mt-1" wire:model.live="kst">
                        <option value="">{{ __('Alla') }}</option>
                        @foreach($costCenters as $costCenter)
                            <option value="{{ $costCenter->code }}">{{ $costCenter->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endif
            @if($projects->isNotEmpty())
                <x-ui.field for="report_project" :label="__('Projekt')">
                    <select id="report_project" class="{{ $input }} mt-1" wire:model.live="projekt">
                        <option value="">{{ __('Alla') }}</option>
                        @foreach($projects as $project)
                            <option value="{{ $project->code }}">{{ $project->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endif
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
            @if($filters->report === 'resultat')
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="jamfor">
                    {{ __('Jämför med samma period förra året') }}
                </label>
            @else
                <span></span>
            @endif
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <a href="{{ route('accounting.reports.csv', $filters->toQueryString()) }}" class="font-medium text-accent-600 hover:text-accent-700">{{ __('Ladda ner för Excel (CSV)') }}</a>
                <button type="button" class="font-medium text-accent-600 hover:text-accent-700" x-on:click="window.print()">{{ __('Skriv ut eller spara som PDF') }}</button>
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="mb-4">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                <h2 class="text-lg font-semibold text-gray-900">{{ $kontoanalys ? __('Kontoanalys för konto :number', ['number' => $filters->account]) : $tabs[$filters->report] }}</h2>
                @if($kontoanalys)
                    <button type="button" wire:click="$set('konto', '')" class="text-sm font-medium text-accent-600 hover:text-accent-700 print:hidden">{{ __('Visa alla konton') }}</button>
                @endif
            </div>
            <p class="text-sm text-gray-600 tabular-nums">
                {{ $filters->from->toDateString() }} – {{ $filters->to->toDateString() }}
                @if($filters->costCenter) · {{ __('Kostnadsställe') }} {{ $filters->costCenter->label() }} @endif
                @if($filters->project) · {{ __('Projekt') }} {{ $filters->project->label() }} @endif
            </p>
            @if($filters->costCenter || $filters->project)
                <p class="mt-1 text-xs text-gray-500">{{ __('Ingående balanser finns per konto, inte per kostnadsställe eller projekt, så de räknas som noll här.') }}</p>
            @endif
        </div>

        <div class="overflow-x-auto">
            @if($filters->report === 'saldobalans')
                @if($data['rows'] === [])
                    <p class="text-sm text-gray-600">{{ __('Inget är bokfört under perioden.') }}</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th class="{{ $th }} {{ $sticky }}">{{ __('Konto') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Ingående') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Debet') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Kredit') }}</th>
                                <th class="py-2 text-right font-medium">{{ __('Utgående') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($data['rows'] as $row)
                                <tr>
                                    <td class="py-2 pr-4 {{ $sticky }}"><a href="{{ $ledgerUrl($row['number']) }}" class="{{ $accountLink }}"><span class="font-semibold tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}</a></td>
                                    <td class="{{ $num }}"><x-ui.amount :money="$m($row['opening'])" :currency="false" /></td>
                                    <td class="{{ $num }}"><x-ui.amount :money="$m($row['debit'])" :currency="false" /></td>
                                    <td class="{{ $num }}"><x-ui.amount :money="$m($row['credit'])" :currency="false" /></td>
                                    <td class="py-2 text-right"><x-ui.amount :money="$m($row['closing'])" :currency="false" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200 font-semibold">
                                <td class="pt-3 pr-4 {{ $sticky }}">{{ __('Summa') }}</td>
                                <td class="pt-3 pr-4 text-right"><x-ui.amount :money="$m($data['totals']['opening'])" :currency="false" /></td>
                                <td class="pt-3 pr-4 text-right"><x-ui.amount :money="$m($data['totals']['debit'])" :currency="false" /></td>
                                <td class="pt-3 pr-4 text-right"><x-ui.amount :money="$m($data['totals']['credit'])" :currency="false" /></td>
                                <td class="pt-3 text-right"><x-ui.amount :money="$m($data['totals']['closing'])" :currency="false" /></td>
                            </tr>
                        </tfoot>
                    </table>
                @endif
            @elseif($filters->report === 'resultat')
                @php($compare = $data['compare_label'] !== null)
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="{{ $th }}">{{ __('Konto') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Perioden') }}</th>
                            @if($compare)
                                <th class="py-2 text-right font-medium">{{ __('Förra året') }}</th>
                            @endif
                        </tr>
                    </thead>
                    @foreach($data['groups'] as $group)
                        <tbody>
                            <tr><td colspan="3" class="pt-5 pb-1 font-semibold text-gray-900">{{ $group['label'] }}</td></tr>
                            @foreach($group['rows'] as $row)
                                <tr class="border-t border-gray-100">
                                    <td class="py-1.5 pr-4"><a href="{{ $ledgerUrl($row['number']) }}" class="{{ $accountLink }}"><span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}</a></td>
                                    <td class="py-1.5 pr-4 text-right"><x-ui.amount :money="$m($row['amount'])" :currency="false" /></td>
                                    @if($compare)
                                        <td class="py-1.5 text-right text-gray-600"><x-ui.amount :money="$m((int) $row['compare'])" :currency="false" /></td>
                                    @endif
                                </tr>
                            @endforeach
                            <tr class="border-t border-gray-200">
                                <td class="py-1.5 pr-4 text-gray-700">{{ __('Summa :group', ['group' => mb_strtolower($group['label'])]) }}</td>
                                <td class="py-1.5 pr-4 text-right font-medium"><x-ui.amount :money="$m($group['total'])" :currency="false" /></td>
                                @if($compare)
                                    <td class="py-1.5 text-right text-gray-600"><x-ui.amount :money="$m((int) $group['compare'])" :currency="false" /></td>
                                @endif
                            </tr>
                            @if($group['subtotal'])
                                <tr class="bg-gray-50 font-semibold">
                                    <td class="py-2 pr-4">{{ $group['subtotal']['label'] }}</td>
                                    <td class="py-2 pr-4 text-right"><x-ui.amount :money="$m($group['subtotal']['amount'])" :currency="false" /></td>
                                    @if($compare)
                                        <td class="py-2 text-right"><x-ui.amount :money="$m((int) $group['subtotal']['compare'])" :currency="false" /></td>
                                    @endif
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 text-base font-semibold">
                            <td class="pt-3 pr-4">{{ __('Beräknat resultat') }}</td>
                            <td class="pt-3 pr-4 text-right"><x-ui.amount :money="$m($data['result'])" :currency="false" /></td>
                            @if($compare)
                                <td class="pt-3 text-right"><x-ui.amount :money="$m((int) $data['compare_result'])" :currency="false" /></td>
                            @endif
                        </tr>
                    </tfoot>
                </table>
                @if($filters->compare && ! $compare)
                    <p class="mt-4 text-sm text-gray-600">{{ __('Det finns inget föregående räkenskapsår att jämföra med.') }}</p>
                @endif
            @elseif($filters->report === 'balans')
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="{{ $th }}">{{ __('Konto') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Ingående') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Förändring') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Utgående') }}</th>
                        </tr>
                    </thead>
                    @foreach($data['sides'] as $sideKey => $side)
                        <tbody>
                            <tr><td colspan="4" class="pt-6 pb-1 text-base font-semibold text-gray-900">{{ $side['label'] }}</td></tr>
                            @foreach($side['groups'] as $group)
                                @continue($group['rows'] === [])
                                <tr><td colspan="4" class="pt-3 pb-1 font-semibold text-gray-700">{{ $group['label'] }}</td></tr>
                                @foreach($group['rows'] as $row)
                                    <tr class="border-t border-gray-100">
                                        <td class="py-1.5 pr-4"><a href="{{ $ledgerUrl($row['number']) }}" class="{{ $accountLink }}"><span class="tabular-nums">{{ $row['number'] }}</span> {{ $row['name'] }}</a></td>
                                        <td class="py-1.5 pr-4 text-right"><x-ui.amount :money="$m($row['opening'])" :currency="false" /></td>
                                        <td class="py-1.5 pr-4 text-right"><x-ui.amount :money="$m($row['change'])" :currency="false" /></td>
                                        <td class="py-1.5 text-right"><x-ui.amount :money="$m($row['closing'])" :currency="false" /></td>
                                    </tr>
                                @endforeach
                            @endforeach
                            @if($sideKey === 'equity' && ($data['unbooked_result']['closing'] !== 0 || $data['unbooked_result']['opening'] !== 0))
                                <tr class="border-t border-gray-100">
                                    <td class="py-1.5 pr-4 italic text-gray-700">{{ __('Beräknat resultat, ännu inte bokfört') }}</td>
                                    <td class="py-1.5 pr-4 text-right"><x-ui.amount :money="$m($data['unbooked_result']['opening'])" :currency="false" /></td>
                                    <td class="py-1.5 pr-4 text-right"><x-ui.amount :money="$m($data['unbooked_result']['change'])" :currency="false" /></td>
                                    <td class="py-1.5 text-right"><x-ui.amount :money="$m($data['unbooked_result']['closing'])" :currency="false" /></td>
                                </tr>
                            @endif
                            <tr class="bg-gray-50 font-semibold">
                                <td class="py-2 pr-4">{{ __('Summa :side', ['side' => mb_strtolower($side['label'])]) }}</td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="$m($side['opening'])" :currency="false" /></td>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="$m($side['change'])" :currency="false" /></td>
                                <td class="py-2 text-right"><x-ui.amount :money="$m($side['closing'])" :currency="false" /></td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            @elseif($filters->report === 'huvudbok')
                @forelse($data as $account)
                    <section class="mb-8" wire:key="ledger-{{ $account['number'] }}">
                        <h3 class="font-semibold text-gray-900">
                            @if($kontoanalys)
                                <span class="tabular-nums">{{ $account['number'] }}</span> {{ $account['name'] }}
                            @else
                                <a href="{{ $ledgerUrl($account['number']) }}" class="{{ $accountLink }}" title="{{ __('Kontoanalys: bara det här kontot') }}"><span class="tabular-nums">{{ $account['number'] }}</span> {{ $account['name'] }}</a>
                            @endif
                        </h3>
                        <table class="mt-2 w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-gray-500">
                                    <th class="{{ $th }} w-28">{{ __('Datum') }}</th>
                                    <th class="{{ $th }} w-20">{{ __('Ver') }}</th>
                                    <th class="{{ $th }}">{{ __('Text') }}</th>
                                    <th class="{{ $th }} text-right">{{ __('Debet') }}</th>
                                    <th class="{{ $th }} text-right">{{ __('Kredit') }}</th>
                                    <th class="py-2 text-right font-medium">{{ __('Saldo') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr class="text-gray-600">
                                    <td colspan="5" class="py-1.5 pr-4">{{ __('Ingående saldo') }}</td>
                                    <td class="py-1.5 text-right"><x-ui.amount :money="$m($account['opening'])" :currency="false" /></td>
                                </tr>
                                @foreach($account['rows'] as $row)
                                    <tr>
                                        <td class="py-1.5 pr-4 tabular-nums">{{ $row['date'] }}</td>
                                        <td class="py-1.5 pr-4"><a href="{{ route('accounting.vouchers.show', $row['voucher_id']) }}" class="font-medium text-accent-600 hover:text-accent-700 print:text-gray-900">{{ $row['reference'] }}</a></td>
                                        <td class="py-1.5 pr-4 text-gray-700">{{ $row['description'] }}</td>
                                        <td class="py-1.5 pr-4 text-right">@if($row['debit'])<x-ui.amount :money="$m($row['debit'])" :currency="false" />@endif</td>
                                        <td class="py-1.5 pr-4 text-right">@if($row['credit'])<x-ui.amount :money="$m($row['credit'])" :currency="false" />@endif</td>
                                        <td class="py-1.5 text-right"><x-ui.amount :money="$m($row['balance'])" :currency="false" /></td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold">
                                    <td colspan="5" class="py-1.5 pr-4">{{ __('Utgående saldo') }}</td>
                                    <td class="py-1.5 text-right"><x-ui.amount :money="$m($account['closing'])" :currency="false" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                @empty
                    <p class="text-sm text-gray-600">{{ $filters->account ? __('Konto :number finns inte i kontoplanen.', ['number' => $filters->account]) : __('Inget är bokfört under perioden.') }}</p>
                @endforelse
            @else
                @forelse($data as $voucher)
                    <section class="mb-6" wire:key="voucher-{{ $voucher->id }}">
                        <h3 class="flex flex-wrap items-baseline gap-x-3 font-semibold text-gray-900">
                            <a href="{{ route('accounting.vouchers.show', $voucher) }}" class="text-accent-600 hover:text-accent-700 print:text-gray-900">{{ $voucher->reference() }}</a>
                            <span class="tabular-nums text-gray-600">{{ $voucher->voucher_date->toDateString() }}</span>
                            <span>{{ $voucher->description }}</span>
                            @if($voucher->correctedBy)
                                <span class="text-sm font-normal text-gray-600">{{ __('Rättad av :reference', ['reference' => $voucher->linkedReference($voucher->correctedBy)]) }}</span>
                            @endif
                            @if($voucher->corrects)
                                <span class="text-sm font-normal text-gray-600">{{ __('Rättar :reference', ['reference' => $voucher->linkedReference($voucher->corrects)]) }}</span>
                            @endif
                        </h3>
                        @php($voucherCostCenters = $listCostCenters || $voucher->rows->contains(fn ($row) => $row->cost_center_id !== null))
                        @php($voucherProjects = $listProjects || $voucher->rows->contains(fn ($row) => $row->project_id !== null))
                        <table class="mt-1 w-full text-sm">
                            <thead class="sr-only">
                                <tr>
                                    <th scope="col">{{ __('Konto') }}</th>
                                    @if($voucherCostCenters)<th scope="col">{{ __('Kostnadsställe') }}</th>@endif
                                    @if($voucherProjects)<th scope="col">{{ __('Projekt') }}</th>@endif
                                    <th scope="col">{{ __('Radtext') }}</th>
                                    <th scope="col">{{ __('Debet') }}</th>
                                    <th scope="col">{{ __('Kredit') }}</th>
                                    <th scope="col">{{ __('Kontosaldo') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($voucher->rows as $row)
                                    <tr>
                                        <td class="py-1 pr-4">@if($row->account)<a href="{{ $ledgerUrl($row->account->number) }}" class="{{ $accountLink }}"><span class="tabular-nums">{{ $row->account->number }}</span> {{ $row->account->name }}</a>@endif</td>
                                        @if($voucherCostCenters)
                                            <td class="py-1 pr-4 text-gray-600">{{ $row->costCenter?->label() }}</td>
                                        @endif
                                        @if($voucherProjects)
                                            <td class="py-1 pr-4 text-gray-600">{{ $row->project?->label() }}</td>
                                        @endif
                                        <td class="py-1 pr-4 text-gray-600">{{ $row->description }}</td>
                                        <td class="py-1 pr-4 text-right w-36">@unless($row->debit_ore->isZero())<x-ui.amount :money="$row->debit_ore" :currency="false" />@endunless</td>
                                        <td class="py-1 pr-4 text-right w-36">@unless($row->credit_ore->isZero())<x-ui.amount :money="$row->credit_ore" :currency="false" />@endunless</td>
                                        <td class="py-1 text-right w-36 text-gray-600">@if($row->account && isset($balances[$row->id]))<x-ui.amount :money="$m(AccountBalances::forDisplay($balances[$row->id], $row->account->type))" :currency="false" />@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @empty
                    <p class="text-sm text-gray-600">{{ __('Inga verifikationer under perioden.') }}</p>
                @endforelse
            @endif
        </div>
    </x-ui.card>
</div>
