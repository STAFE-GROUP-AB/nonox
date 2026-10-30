@php
    use App\Support\Money\Money;

    $kr = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);
    $signed = fn (int $ore): string => ($ore < 0 ? '−' : '').Money::ofOre(abs($ore))->format(withCurrency: false);
    $percent = fn (int $bp): string => rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',').' %';
    $date = fn ($day): string => $day->locale('sv')->translatedFormat('j F Y');
    $tax = $payslip->tax_explanation ?? [];
    $regular = $tax['regular'] ?? $tax;
    $taxNote = match ($regular['method'] ?? null) {
        'table' => __('Tabell :table, kolumn :column', ['table' => $regular['table'], 'column' => $regular['column']]),
        default => null,
    };
    $month = ucfirst($run->periodStart()->locale('sv')->translatedFormat('F Y'));
    $address = trim(collect([$company->address_line1, trim($company->postal_code.' '.$company->city)])->filter()->implode(', '));
    $contact = collect([$company->email, $company->phone])->filter()->implode(' · ');
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Lönebesked') }} {{ $month }}</title>
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; line-height: 1.35; color: #1f2933; }
    .page { padding: 16mm 16mm 26mm; }
    .bar { height: 3.5mm; background: #9e2a1b; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; padding: 0; }
    .muted { color: #616e7c; }
    .right { text-align: right; }
    .num { text-align: right; white-space: nowrap; }
    .label { font-size: 6.8pt; letter-spacing: 0.9pt; text-transform: uppercase; color: #616e7c; }
    .eyebrow { font-size: 7.2pt; letter-spacing: 1.4pt; text-transform: uppercase; color: #9e2a1b; font-weight: bold; }
    .company { font-size: 12.5pt; font-weight: bold; }
    .month { font-size: 21pt; font-weight: bold; letter-spacing: -0.4pt; margin-top: 1mm; }
    .card { border: 0.3mm solid #d9dee4; border-radius: 1.8mm; padding: 3.2mm 4mm; }
    .facts td { padding: 0.6mm 0; }
    .tile { border-radius: 1.8mm; padding: 3.2mm 4mm; background: #f4f5f7; }
    .tile.net { background: #9e2a1b; color: #ffffff; }
    .tile .amount { font-size: 13pt; font-weight: bold; margin-top: 1mm; white-space: nowrap; }
    .tile.net .label { color: #f6d9d3; }
    .tile.net .amount { font-size: 15pt; }
    .lines { table-layout: fixed; margin-top: 7mm; }
    .lines th { font-size: 6.8pt; letter-spacing: 0.9pt; text-transform: uppercase; color: #616e7c; font-weight: normal; text-align: left; padding: 0 0 1.8mm; border-bottom: 0.4mm solid #1f2933; }
    .lines th.num { text-align: right; }
    .lines td { padding: 2mm 0; border-bottom: 0.2mm solid #e4e7eb; }
    .lines .section td { padding: 4mm 0 1.2mm; border-bottom: none; font-weight: bold; font-size: 7.6pt; color: #9e2a1b; letter-spacing: 0.6pt; text-transform: uppercase; }
    .lines .subtotal td { border-bottom: none; font-weight: bold; padding-top: 2.2mm; }
    .lines .total td { border-top: 0.6mm solid #1f2933; border-bottom: none; padding-top: 3mm; font-size: 12pt; font-weight: bold; }
    .lines .note { display: block; font-size: 7.2pt; color: #616e7c; }
    .preview { position: fixed; top: 105mm; left: 0; right: 0; text-align: center; font-size: 72pt; font-weight: bold; color: #9e2a1b; opacity: 0.08; transform: rotate(-28deg); letter-spacing: 6pt; }
    .preview-note { background: #fbeeeb; color: #86231a; border-radius: 1.8mm; padding: 2.4mm 4mm; margin-bottom: 6mm; font-weight: bold; }
    .notice { margin-top: 4mm; border-left: 0.8mm solid #9e2a1b; padding: 1.5mm 3mm; background: #fbeeeb; }
    .footer { position: fixed; bottom: 9mm; left: 16mm; right: 16mm; font-size: 6.8pt; color: #616e7c; border-top: 0.2mm solid #d9dee4; padding-top: 2.4mm; }
</style>
</head>
<body>
    <div class="bar"></div>
    @if($preview)
        <div class="preview">{{ __('UTKAST') }}</div>
    @endif

    <div class="footer">
        <table>
            <tr>
                <td>{{ $company->legal_name ?: $company->name }} · {{ __('Org.nr') }} {{ $company->org_number }}@if($contact) · {{ __('Frågor om lönen:') }} {{ $contact }}@endif</td>
                <td class="right">{{ __('Lönebesked :month · anställd :number', ['month' => mb_strtolower($month), 'number' => $employee->employee_number]) }}</td>
            </tr>
        </table>
    </div>

    <div class="page">
        @if($preview)
            <div class="preview-note">{{ __('Förhandsgranskning. Lönekörningen är inte attesterad och beloppen kan ändras.') }}</div>
        @endif

        <table>
            <tr>
                <td style="width: 55%">
                    <div class="company">{{ $company->legal_name ?: $company->name }}</div>
                    <div class="muted">{{ __('Org.nr') }} {{ $company->org_number }}</div>
                    @if($address)<div class="muted">{{ $address }}</div>@endif
                </td>
                <td class="right">
                    <div class="eyebrow">{{ __('Lönebesked') }}</div>
                    <div class="month">{{ $month }}</div>
                    <div class="muted">{{ __('Utbetalning :date', ['date' => $date($run->payment_date)]) }}</div>
                </td>
            </tr>
        </table>

        <table style="margin-top: 8mm">
            <tr>
                <td style="width: 49%" class="card">
                    <div class="label">{{ __('Anställd') }}</div>
                    <div style="font-size: 11pt; font-weight: bold; margin: 1mm 0 1.5mm">{{ $employee->first_name }} {{ $employee->last_name }}</div>
                    <table class="facts">
                        <tr><td class="muted">{{ __('Anställningsnummer') }}</td><td class="right">{{ $employee->employee_number }}</td></tr>
                        <tr><td class="muted">{{ __('Personnummer') }}</td><td class="right">{{ $personalNumber }}</td></tr>
                        @if($payslip->employment?->position_title)
                            <tr><td class="muted">{{ __('Befattning') }}</td><td class="right">{{ $payslip->employment->position_title }}</td></tr>
                        @endif
                    </table>
                </td>
                <td style="width: 2%"></td>
                <td style="width: 49%" class="card">
                    <div class="label">{{ __('Lön och skatt') }}</div>
                    <table class="facts" style="margin-top: 1.5mm">
                        <tr><td class="muted">{{ __('Period') }}</td><td class="right">{{ $run->periodStart()->toDateString() }} – {{ $run->periodEnd()->toDateString() }}</td></tr>
                        @if($salary)<tr><td class="muted">{{ __('Lön') }}</td><td class="right">{{ $salary }}</td></tr>@endif
                        @if($taxNote)<tr><td class="muted">{{ __('Skattetabell') }}</td><td class="right">{{ $taxNote }}</td></tr>@endif
                        <tr><td class="muted">{{ __('Utbetalas till') }}</td><td class="right">{{ $account ?? __('Konto saknas') }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <table style="margin-top: 5mm">
            <tr>
                <td style="width: 32%" class="tile"><div class="label">{{ __('Bruttolön') }}</div><div class="amount">{{ $kr($payslip->gross_ore->ore) }} kr</div></td>
                <td style="width: 2%"></td>
                <td style="width: 32%" class="tile"><div class="label">{{ __('Skatt') }}</div><div class="amount">{{ $kr($payslip->tax_ore->ore) }} kr</div></td>
                <td style="width: 2%"></td>
                <td style="width: 32%" class="tile net"><div class="label">{{ __('Att få utbetalt') }}</div><div class="amount">{{ $kr($payslip->net_ore->ore) }} kr</div></td>
            </tr>
        </table>

        <table class="lines">
            <colgroup>
                <col style="width: 46%"><col style="width: 12%"><col style="width: 8%"><col style="width: 15%"><col style="width: 19%">
            </colgroup>
            <thead>
                <tr>
                    <th>{{ __('Löneart') }}</th>
                    <th class="num">{{ __('Antal') }}</th>
                    <th>&nbsp;{{ __('Enhet') }}</th>
                    <th class="num">{{ __('À-pris') }}</th>
                    <th class="num">{{ __('Belopp') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr class="section"><td colspan="5">{{ __('Lön och ersättningar') }}</td></tr>
                @foreach($earnings as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ $row['quantity'] }}</td>
                        <td>&nbsp;{{ $row['unit'] }}</td>
                        <td class="num">{{ $row['rate'] !== null ? $kr($row['rate']) : '' }}</td>
                        <td class="num">{{ $signed($row['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal"><td colspan="4">{{ __('Summa lön och ersättningar') }}</td><td class="num">{{ $signed($earningsTotal) }}</td></tr>

                <tr class="section"><td colspan="5">{{ __('Skatt') }}</td></tr>
                <tr>
                    <td colspan="4">{{ __('Preliminär skatt') }}@if($taxNote)<span class="note">{{ $taxNote }}</span>@endif</td>
                    <td class="num">{{ $signed(-$payslip->tax_ore->ore) }}</td>
                </tr>

                @if($afterTax !== [])
                    <tr class="section"><td colspan="5">{{ __('Efter skatt') }}</td></tr>
                    @foreach($afterTax as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="num">{{ $row['quantity'] }}</td>
                            <td>&nbsp;{{ $row['unit'] }}</td>
                            <td class="num">{{ $row['rate'] !== null ? $kr($row['rate']) : '' }}</td>
                            <td class="num">{{ $signed($row['amount']) }}</td>
                        </tr>
                    @endforeach
                @endif

                <tr class="total"><td colspan="4">{{ __('Att få utbetalt') }}</td><td class="num">{{ $kr($payslip->net_ore->ore) }} kr</td></tr>
            </tbody>
        </table>

        <table style="margin-top: 8mm">
            <tr>
                <td style="width: 49%" class="card">
                    <div class="label">{{ __('Hittills i år') }}</div>
                    <table class="facts" style="margin-top: 1.5mm">
                        <tr><td class="muted">{{ __('Bruttolön') }}</td><td class="num">{{ $kr($ytd['gross']) }}</td></tr>
                        <tr><td class="muted">{{ __('Skatt') }}</td><td class="num">{{ $kr($ytd['tax']) }}</td></tr>
                        <tr><td class="muted">{{ __('Utbetalt') }}</td><td class="num">{{ $kr($ytd['net']) }}</td></tr>
                    </table>
                    @if($preview)<div class="muted" style="margin-top: 1mm; font-size: 7pt">{{ __('Räknas med när lönen är attesterad.') }}</div>@endif
                </td>
                <td style="width: 2%"></td>
                <td style="width: 49%" class="card">
                    <div class="label">{{ __('Arbetsgivaren betalar också') }}</div>
                    <table class="facts" style="margin-top: 1.5mm">
                        <tr>
                            <td class="muted">{{ __('Arbetsgivaravgifter') }}@if(count($contributionRates) === 1) {{ $percent($contributionRates[0]) }}@endif</td>
                            <td class="num">{{ $kr($payslip->employer_contribution_ore->ore) }}</td>
                        </tr>
                        <tr><td><strong>{{ __('Total kostnad för lönen') }}</strong></td><td class="num"><strong>{{ $kr($payslip->gross_ore->ore + $payslip->employer_contribution_ore->ore) }}</strong></td></tr>
                    </table>
                    <div class="muted" style="margin-top: 1mm; font-size: 7pt">{{ __('Betalas till Skatteverket, inte dragna från din lön.') }}</div>
                </td>
            </tr>
        </table>

        @foreach($payslip->warnings as $warning)
            <div class="notice">{{ $warning }}</div>
        @endforeach
    </div>
</body>
</html>
