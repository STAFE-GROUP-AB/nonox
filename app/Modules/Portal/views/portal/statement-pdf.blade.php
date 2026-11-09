@php($kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format())
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Kontoutdrag') }}</title>
<style>
    @page { margin: 18mm 16mm 22mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2933; }
    h1 { font-size: 18pt; margin: 0 0 2mm; }
    .muted { color: #616e7c; }
    table { width: 100%; border-collapse: collapse; }
    .rows th, .rows td { padding: 1.5mm 2mm 1.5mm 0; border-bottom: 1px solid #e4e7eb; text-align: left; }
    .num { text-align: right !important; }
    .box { border: 1px solid #cbd2d9; border-radius: 2mm; padding: 4mm; margin-top: 8mm; }
    @include('invoicing.partials.brand-style')
</style>
</head>
<body>
    <div class="brand-bar"></div>
    <table>
        <tr>
            <td style="width: 55%; vertical-align: top;">@include('invoicing.partials.brand-sender')</td>
            <td style="vertical-align: top;">
                <h1>{{ __('Kontoutdrag') }}</h1>
                <span class="muted">{{ __('Öppna poster den :date', ['date' => $statement['date']->toDateString()]) }}</span><br>
                <span>{{ $customer->name }}</span>
            </td>
        </tr>
    </table>

    <table class="rows" style="margin-top: 8mm;">
        <thead>
            <tr><th>{{ __('Faktura') }}</th><th>{{ __('Fakturadatum') }}</th><th>{{ __('Förfallodag') }}</th><th class="num">{{ __('Belopp') }}</th><th class="num">{{ __('Betalt och krediterat') }}</th><th class="num">{{ __('Öppet') }}</th></tr>
        </thead>
        <tbody>
            @forelse($statement['rows'] as $row)
                <tr>
                    <td>{{ $row['invoice']->title() }}</td>
                    <td>{{ $row['invoice']->invoice_date->toDateString() }}</td>
                    <td>{{ $row['invoice']->due_date->toDateString() }}</td>
                    <td class="num">{{ $kr($row['total']) }}</td>
                    <td class="num">{{ $kr($row['paid'] + $row['credited']) }}</td>
                    <td class="num">{{ $kr($row['open']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">{{ __('Inga öppna poster den dagen.') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr><th colspan="5" class="num">{{ __('Summa öppet') }}</th><td class="num"><strong>{{ $kr($statement['total']) }}</strong></td></tr>
            <tr><th colspan="5" class="num">{{ __('Varav förfallet') }}</th><td class="num">{{ $kr($statement['overdue']) }}</td></tr>
        </tfoot>
    </table>

    <div class="box"><span class="muted">{{ __('Kontoutdraget är en sammanställning från kundportalen, räknad från fakturorna och de betalningar som hade kommit in den dagen. Det är inte ett dokument som :company har utfärdat; fakturorna gäller.', ['company' => $company->legal_name ?: $company->name]) }}</span></div>
</body>
</html>
