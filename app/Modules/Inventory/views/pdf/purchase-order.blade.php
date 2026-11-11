@php
    use App\Support\Money\Money;

    $supplier = $order['supplier'] ?? [];
    $kr = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Inköpsorder :number', ['number' => $order['number']]) }}</title>
<style>
    @page { margin: 18mm 16mm 22mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2933; }
    h1 { font-size: 20pt; margin: 0 0 2mm; letter-spacing: -0.3pt; }
    .muted { color: #616e7c; }
    .right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .meta td { padding: 1mm 0; }
    .rows th { text-align: left; font-weight: normal; color: #616e7c; border-bottom: 1px solid #cbd2d9; padding: 2mm 2mm 2mm 0; }
    .rows th.right { text-align: right; }
    .rows td { border-bottom: 1px solid #e4e7eb; padding: 2mm 2mm 2mm 0; vertical-align: top; }
    .sum td { padding: 1mm 0; }
    .total td { font-size: 12pt; font-weight: bold; border-top: 2px solid #1f2933; padding-top: 2mm; }
    @include('invoicing.partials.brand-style')
    .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7.5pt; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 2mm; }
</style>
</head>
<body>
    <div class="brand-bar"></div>
    <table class="head">
        <tr>
            <td style="width: 55%">
                @include('invoicing.partials.brand-sender')
            </td>
            <td>
                <h1>{{ __('Inköpsorder') }}</h1>
                <table class="meta">
                    <tr><td class="muted">{{ __('Ordernummer') }}</td><td class="right">{{ $order['number'] }}</td></tr>
                    @if(($order['version'] ?? 1) > 1)
                        <tr><td class="muted">{{ __('Version') }}</td><td class="right">{{ $order['version'] }}</td></tr>
                    @endif
                    <tr><td class="muted">{{ __('Orderdatum') }}</td><td class="right">{{ $order['order_date'] }}</td></tr>
                    @if($order['requested_delivery_on'])
                        <tr><td class="muted">{{ __('Önskat leveransdatum') }}</td><td class="right">{{ $order['requested_delivery_on'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="head" style="margin-top: 8mm">
        <tr>
            <td style="width: 55%">
                <span class="muted">{{ __('Leverantör') }}</span><br>
                <strong>{{ $supplier['name'] ?? '' }}</strong><br>
                @if(! empty($supplier['address_line1'])){{ $supplier['address_line1'] }}<br>@endif
                {{ trim(($supplier['postal_code'] ?? '').' '.($supplier['city'] ?? '')) }}
            </td>
            <td>
                @if($order['delivery_address'])
                    <span class="muted">{{ __('Leveransadress') }}</span><br>
                    {!! nl2br(e($order['delivery_address'])) !!}<br>
                @endif
                @if($order['our_reference'])<span class="muted">{{ __('Vår referens') }}:</span> {{ $order['our_reference'] }}@endif
            </td>
        </tr>
    </table>

    @if(($order['version'] ?? 1) > 1)
        <p style="margin-top: 6mm"><strong>{{ __('Den här versionen ersätter tidigare versioner av ordern.') }}</strong></p>
    @endif

    <table class="rows" style="margin-top: 8mm">
        <thead>
            <tr>
                <th>{{ __('Ert art.nr') }}</th>
                <th>{{ __('Benämning') }}</th>
                <th class="right">{{ __('Antal') }}</th>
                <th class="right">{{ __('À-pris') }}</th>
                <th class="right">{{ __('Belopp') }}</th>
                <th class="right">{{ __('Leveransdatum') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order['rows'] as $row)
                <tr>
                    <td>{{ $row['supplier_article_number'] }}</td>
                    <td>{{ $row['description'] }}@if($row['article_number'])<br><span class="muted">{{ __('Vårt art.nr :number', ['number' => $row['article_number']]) }}</span>@endif</td>
                    <td class="right">{{ $quantity($row['quantity']) }} {{ $row['unit'] }}</td>
                    <td class="right">{{ $kr($row['price_ore']) }}</td>
                    <td class="right">{{ $kr($row['value_ore']) }}</td>
                    <td class="right">{{ $row['expected_on'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 6mm">
        <tr>
            <td style="width: 50%"></td>
            <td>
                <table class="sum">
                    <tr class="total"><td>{{ __('Summa exkl. moms') }}</td><td class="right">{{ Money::ofOre((int) $order['total_ore'])->format() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if($order['notes'])
        <p style="margin-top: 8mm">{!! nl2br(e($order['notes'])) !!}</p>
    @endif

    <p class="muted" style="margin-top: 8mm">{{ __('Bekräfta gärna ordern och leveransdatum genom att svara på mejlet eller kontakta oss.') }}</p>

    <div class="footer">
        {{ $company->legal_name ?: $company->name }} · {{ __('Org.nr') }} {{ $company->org_number }}
        @if($company->vat_number) · {{ __('Momsreg.nr') }} {{ $company->vat_number }} @endif
        @if($company->email) · {{ $company->email }} @endif
        @if($company->phone) · {{ $company->phone }} @endif
    </div>
</body>
</html>
