@php
    $kr = fn (?int $ore): string => $ore === null ? '–' : App\Support\Money\Money::ofOre($ore)->format();
    $th = 'px-2 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500';
    $thNum = 'px-2 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500';
    $td = 'px-2 py-2 text-gray-900';
    $tdNum = 'px-2 py-2 text-right tabular-nums whitespace-nowrap text-gray-900';
@endphp

<div class="space-y-6" data-statistics>
    @if($stats === null)
        <x-ui.empty-state :title="__('Ingen statistik ännu')">
            {{ __('Statistiken bygger på bokföringen. Skapa ett räkenskapsår och bokför, så fylls diagrammen på.') }}
        </x-ui.empty-state>
    @else
        @php
            $sumOf = fn (?array $values): int => (int) array_sum(array_map('intval', array_filter($values ?? [], fn ($v) => $v !== null)));
            $months = $stats['months'];
            $labels = [
                'revenue' => __('Intäkter :year', ['year' => $stats['year']]),
                'costs' => __('Kostnader :year', ['year' => $stats['year']]),
                'revenuePrevious' => __('Intäkter :year', ['year' => $stats['previous_year'] ?? '']),
                'costsPrevious' => __('Kostnader :year', ['year' => $stats['previous_year'] ?? '']),
                'result' => __('Resultat per månad'),
                'accumulated' => __('Ackumulerat resultat'),
                'balance' => __('Saldo bank och kassa'),
                'forecast' => __('Prognos'),
                'invoiced' => __('Fakturerat exkl. moms'),
                'cost' => __('Kostnad'),
                'output' => __('Utgående moms'),
                'input' => __('Ingående moms'),
                'net' => __('Att betala (netto)'),
                'customerInvoices' => __('Kundfakturor'),
                'supplierInvoices' => __('Leverantörsfakturor'),
                'recurring' => __('Avtalsfakturor'),
                'payroll' => __('Lön och arbetsgivarskatter'),
                'vatFlow' => __('Moms'),
                'taxAccount' => __('Skattekontot'),
                'trend' => __('Snittresultat'),
            ];
            $liquidity = $stats['liquidity'];
            $included = $liquidity['included'] ?? ['recurring' => false, 'payroll' => false, 'vat' => false, 'preliminary_tax' => false];
            $forecastNote = __('Så räknas prognosen: dagens saldo på bank och kassa (konto 1900–1999), plus obetalda kundfakturor och minus obetalda leverantörsfakturor den månad de förfaller, plus kommande fakturor från avtal på sina förfallodagar. Lön räknas från lönekörningarna: nettolönen på utbetalningsdagen, skatt och arbetsgivaravgifter den dag de ska betalas till Skatteverket, och en månad utan lönekörning som den senaste. Momsen räknas per period på den dag den ska deklareras och betalas, med det som är bokfört hittills. Skattekontot är F-skatten med månadsbeloppet ni har fyllt i och saldot på konto 1630; pengar på skattekontot betalar skatterna först. Sist läggs det genomsnittliga resultatet för de tre senaste hela månaderna till (:average per månad; för den här månaden bara de dagar som är kvar), utan det som redan räknas för sig. Förfallna belopp räknas i den här månaden. Det är en enkel räkning, ingen AI och ingen garanti.', ['average' => $kr($liquidity['average_result'])]);

            if (! $included['payroll']) {
                $forecastNote .= ' '.__('Lön ingår bara genom resultatet, eftersom lönemodulen inte är på eller inte har någon lönekörning de senaste tre månaderna.');
            }

            if (! $included['preliminary_tax']) {
                $forecastNote .= ' '.__('F-skatt ingår bara genom resultatet tills ni fyller i Debiterad preliminärskatt per månad under företagets uppgifter.');
            }
            $lastForecast = $liquidity['forecast'] === [] ? null : end($liquidity['forecast']);
        @endphp

        <p class="text-sm text-gray-600">{{ __('Räkenskapsåret :year, ur den bokförda bokföringen. Uppdateras när du bokför och när du laddar om översikten.', ['year' => $stats['year']]) }}</p>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-overview.chart kind="revenue" :title="__('Intäkter och kostnader per månad')" :data="$stats" :labels="$labels"
                :summary="$stats['previous_year'] === null
                    ? __('Intäkter hittills i år :revenue, kostnader :costs.', ['revenue' => $kr($sumOf($stats['revenue']['current'])), 'costs' => $kr($sumOf($stats['costs']['current']))])
                    : __('Intäkter hittills i år :revenue, kostnader :costs. Streckat: :previous.', ['revenue' => $kr($sumOf($stats['revenue']['current'])), 'costs' => $kr($sumOf($stats['costs']['current'])), 'previous' => $stats['previous_year']])">
                <table class="min-w-full text-sm">
                    <thead><tr>
                        <th scope="col" class="{{ $th }}">{{ __('Månad') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ $labels['revenue'] }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ $labels['costs'] }}</th>
                        @if($stats['previous_year'])
                            <th scope="col" class="{{ $thNum }}">{{ $labels['revenuePrevious'] }}</th>
                            <th scope="col" class="{{ $thNum }}">{{ $labels['costsPrevious'] }}</th>
                        @endif
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($months as $i => $month)
                            <tr>
                                <th scope="row" class="{{ $td }} text-left font-medium">{{ $month }}</th>
                                <td class="{{ $tdNum }}">{{ $kr($stats['revenue']['current'][$i]) }}</td>
                                <td class="{{ $tdNum }}">{{ $kr($stats['costs']['current'][$i]) }}</td>
                                @if($stats['previous_year'])
                                    <td class="{{ $tdNum }}">{{ $kr($stats['revenue']['previous'][$i]) }}</td>
                                    <td class="{{ $tdNum }}">{{ $kr($stats['costs']['previous'][$i]) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-overview.chart>

            <x-overview.chart kind="result" :title="__('Resultat per månad och ackumulerat')" :data="$stats" :labels="$labels"
                :summary="__('Resultat hittills i år :result.', ['result' => $kr($sumOf($stats['result']['monthly']))])">
                <table class="min-w-full text-sm">
                    <thead><tr>
                        <th scope="col" class="{{ $th }}">{{ __('Månad') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ $labels['result'] }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ $labels['accumulated'] }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($months as $i => $month)
                            <tr>
                                <th scope="row" class="{{ $td }} text-left font-medium">{{ $month }}</th>
                                <td class="{{ $tdNum }}">{{ $kr($stats['result']['monthly'][$i]) }}</td>
                                <td class="{{ $tdNum }}">{{ $kr($stats['result']['accumulated'][$i]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-overview.chart>

            <x-overview.chart kind="liquidity" class="xl:col-span-2" :title="__('Likviditet och prognos')" :data="$stats" :labels="$labels"
                :summary="$lastForecast === null
                    ? __('Saldo på bank och kassa i dag :balance.', ['balance' => $kr($liquidity['today'])])
                    : __('Saldo på bank och kassa i dag :balance. Prognos för slutet av :month: :forecast (streckat).', ['balance' => $kr($liquidity['today']), 'month' => $lastForecast['label'], 'forecast' => $kr($lastForecast['balance'])])"
                :note="$forecastNote">
                @php
                    $parts = [
                        'customerInvoices' => fn (array $point): int => $point['inflow'],
                        'supplierInvoices' => fn (array $point): int => -$point['outflow'],
                        'recurring' => fn (array $point): int => $point['recurring'] ?? 0,
                        'payroll' => fn (array $point): int => $point['payroll'] ?? 0,
                        'vatFlow' => fn (array $point): int => $point['vat'] ?? 0,
                        'taxAccount' => fn (array $point): int => $point['tax_account'] ?? 0,
                        'trend' => fn (array $point): int => $point['trend'],
                    ];
                @endphp
                <table class="min-w-full text-sm">
                    <thead><tr>
                        <th scope="col" class="{{ $th }}">{{ __('Tidpunkt') }}</th>
                        @foreach($parts as $key => $value)
                            <th scope="col" class="{{ $thNum }}">{{ $labels[$key] }}</th>
                        @endforeach
                        <th scope="col" class="{{ $thNum }}">{{ __('Saldo') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($liquidity['actual'] as $point)
                            <tr>
                                <th scope="row" class="{{ $td }} text-left font-medium">{{ $point['label'] }}</th>
                                @foreach($parts as $key => $value)
                                    <td class="{{ $tdNum }}">–</td>
                                @endforeach
                                <td class="{{ $tdNum }}">{{ $kr($point['balance']) }}</td>
                            </tr>
                        @endforeach
                        @foreach($liquidity['forecast'] as $point)
                            <tr class="bg-paper/60">
                                <th scope="row" class="{{ $td }} text-left font-medium">{{ __('Prognos :month', ['month' => $point['label']]) }}</th>
                                @foreach($parts as $key => $value)
                                    <td class="{{ $tdNum }}">{{ $kr($value($point)) }}</td>
                                @endforeach
                                <td class="{{ $tdNum }}">{{ $kr($point['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-overview.chart>

            @if($stats['top_customers'] !== null)
                <x-overview.chart kind="customers" :title="__('Största kunderna i år')" :data="$stats" :labels="$labels"
                    :summary="$stats['top_customers'] === []
                        ? __('Inga fakturor i år ännu.')
                        : __('Största kund: :name, :amount exkl. moms.', ['name' => $stats['top_customers'][0]['name'], 'amount' => $kr($stats['top_customers'][0]['amount'])])">
                    <table class="min-w-full text-sm">
                        <thead><tr>
                            <th scope="col" class="{{ $th }}">{{ __('Kund') }}</th>
                            <th scope="col" class="{{ $thNum }}">{{ $labels['invoiced'] }}</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($stats['top_customers'] as $row)
                                <tr><th scope="row" class="{{ $td }} text-left font-medium">{{ $row['name'] }}</th><td class="{{ $tdNum }}">{{ $kr($row['amount']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-overview.chart>
            @endif

            <x-overview.chart kind="accounts" :title="__('Största kostnaderna i år')" :data="$stats" :labels="$labels"
                :summary="$stats['top_accounts'] === []
                    ? __('Inga kostnader bokförda i år ännu.')
                    : __('Största kostnad: :account, :amount.', ['account' => $stats['top_accounts'][0]['number'].' '.$stats['top_accounts'][0]['name'], 'amount' => $kr($stats['top_accounts'][0]['amount'])])">
                <table class="min-w-full text-sm">
                    <thead><tr>
                        <th scope="col" class="{{ $th }}">{{ __('Konto') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ $labels['cost'] }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($stats['top_accounts'] as $row)
                            <tr><th scope="row" class="{{ $td }} text-left font-medium">{{ $row['number'] }} {{ $row['name'] }}</th><td class="{{ $tdNum }}">{{ $kr($row['amount']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </x-overview.chart>

            <x-overview.chart kind="vat" :title="__('Moms per månad')" :data="$stats" :labels="$labels"
                :summary="__('Utgående moms hittills i år :output, ingående :input. Momsens egna avräkningar är inte med.', ['output' => $kr($sumOf($stats['vat']['output'])), 'input' => $kr($sumOf($stats['vat']['input']))])">
                <table class="min-w-full text-sm">
                    <thead><tr>
                        <th scope="col" class="{{ $th }}">{{ __('Månad') }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ $labels['output'] }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ $labels['input'] }}</th>
                        <th scope="col" class="{{ $thNum }}">{{ $labels['net'] }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($months as $i => $month)
                            @php($out = $stats['vat']['output'][$i])
                            <tr>
                                <th scope="row" class="{{ $td }} text-left font-medium">{{ $month }}</th>
                                <td class="{{ $tdNum }}">{{ $kr($out) }}</td>
                                <td class="{{ $tdNum }}">{{ $kr($stats['vat']['input'][$i]) }}</td>
                                <td class="{{ $tdNum }}">{{ $out === null ? '–' : $kr($out - (int) $stats['vat']['input'][$i]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-overview.chart>
        </div>
    @endif
</div>
