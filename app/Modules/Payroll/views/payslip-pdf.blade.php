@php
    use App\Support\Money\Money;

    $kr = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);
    $tax = $payslip->tax_explanation ?? [];
    $regular = $tax['regular'] ?? $tax;
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Lönebesked') }} {{ $run->period }}</title>
<style>
    @page { margin: 18mm 16mm 22mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2933; }
    h1 { font-size: 18pt; margin: 0 0 2mm; }
    .muted { color: #616e7c; }
    .right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .rows th { text-align: left; font-weight: normal; color: #616e7c; border-bottom: 1px solid #cbd2d9; padding: 2mm 2mm 2mm 0; }
    .rows td { border-bottom: 1px solid #e4e7eb; padding: 2mm 2mm 2mm 0; }
    .sum td { padding: 1mm 0; }
    .total td { font-size: 12pt; font-weight: bold; border-top: 2px solid #1f2933; padding-top: 2mm; }
    .box { border: 1px solid #cbd2d9; border-radius: 2mm; padding: 4mm; margin-top: 8mm; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 55%">
                <strong style="font-size: 12pt">{{ $company->legal_name ?: $company->name }}</strong><br>
                <span class="muted">{{ __('Org.nr') }} {{ $company->org_number }}</span>
            </td>
            <td>
                <h1>{{ __('Lönebesked') }}</h1>
                <table>
                    <tr><td class="muted">{{ __('Period') }}</td><td class="right">{{ $run->periodStart()->toDateString() }} – {{ $run->periodEnd()->toDateString() }}</td></tr>
                    <tr><td class="muted">{{ __('Utbetalningsdag') }}</td><td class="right">{{ $run->payment_date->toDateString() }}</td></tr>
                    <tr><td class="muted">{{ __('Anställd') }}</td><td class="right">{{ $employee->employee_number }} {{ $employee->first_name }} {{ $employee->last_name }}</td></tr>
                    @if(isset($regular['table']))
                        <tr><td class="muted">{{ __('Skattetabell') }}</td><td class="right">{{ __(':table, kolumn :column', ['table' => $regular['table'], 'column' => $regular['column']]) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="rows" style="margin-top: 10mm">
        <thead><tr><th>{{ __('Lönerad') }}</th><th class="right">{{ __('Antal') }}</th><th class="right">{{ __('Belopp') }}</th></tr></thead>
        <tbody>
            @foreach($payslip->lines as $line)
                @continue($line->tax_effect === 'NET')
                <tr><td>{{ $line->description }}</td><td class="right">{{ $line->quantity !== null ? rtrim(rtrim(str_replace('.', ',', (string) $line->quantity), '0'), ',') : '' }}</td><td class="right">{{ $kr($line->amount_ore->ore) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="sum" style="width: 55%; margin-left: 45%; margin-top: 4mm">
        <tr><td>{{ __('Bruttolön') }}</td><td class="right">{{ $kr($payslip->gross_ore->ore) }}</td></tr>
        <tr><td>{{ __('Skatteavdrag') }}</td><td class="right">−{{ $kr($payslip->tax_ore->ore) }}</td></tr>
        @foreach($payslip->lines as $line)
            @if($line->tax_effect === 'NET')
                <tr><td>{{ $line->description }}</td><td class="right">{{ $kr($line->amount_ore->ore) }}</td></tr>
            @endif
        @endforeach
        <tr class="total"><td>{{ __('Att få utbetalt') }}</td><td class="right">{{ $kr($payslip->net_ore->ore) }} kr</td></tr>
    </table>

    <div class="box">
        <table>
            <tr>
                <td class="muted">{{ __('Hittills i år') }}</td>
                <td>{{ __('Brutto') }} {{ $kr($ytd['gross']) }}</td>
                <td>{{ __('Skatt') }} {{ $kr($ytd['tax']) }}</td>
                <td>{{ __('Netto') }} {{ $kr($ytd['net']) }}</td>
            </tr>
            @if($account)
                <tr><td class="muted">{{ __('Utbetalas till') }}</td><td colspan="3">{{ $account }}</td></tr>
            @endif
        </table>
    </div>

    @foreach($payslip->warnings as $warning)
        <p class="muted" style="margin-top: 4mm">{{ $warning }}</p>
    @endforeach
</body>
</html>
