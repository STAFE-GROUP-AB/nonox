@php
    use App\Support\Money\Money;

    $customer = $document->customer?->snapshot() ?? [];
    $kr = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);
    $qty = fn (string $quantity): string => rtrim(rtrim(str_replace('.', ',', $quantity), '0'), ',');
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $document->title() }}</title>
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
    .rows td { border-bottom: 1px solid #e4e7eb; padding: 2mm 2mm 2mm 0; }
    .sum td { padding: 1mm 0; }
    .total td { font-size: 12pt; font-weight: bold; border-top: 2px solid #1f2933; padding-top: 2mm; }
    .box { border: 1px solid #cbd2d9; border-radius: 2mm; padding: 4mm; margin-top: 8mm; }
    .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7.5pt; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 2mm; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 55%">
                <strong style="font-size: 12pt">{{ $company->legal_name ?: $company->name }}</strong><br>
                <span class="muted">{{ $company->address_line1 }}@if($company->address_line2)<br>{{ $company->address_line2 }}@endif<br>{{ trim($company->postal_code.' '.$company->city) }}</span>
            </td>
            <td>
                <h1>{{ $document->isQuote() ? __('Offert') : __('Orderbekräftelse') }}</h1>
                <table class="meta">
                    <tr><td class="muted">{{ $document->isQuote() ? __('Offertnummer') : __('Ordernummer') }}</td><td class="right">{{ $document->number }}</td></tr>
                    <tr><td class="muted">{{ __('Datum') }}</td><td class="right">{{ $document->document_date->toDateString() }}</td></tr>
                    @if($document->until_date)
                        <tr><td class="muted">{{ $document->isQuote() ? __('Giltig till') : __('Leveransdatum') }}</td><td class="right">{{ $document->until_date->toDateString() }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="head" style="margin-top: 8mm">
        <tr>
            <td style="width: 55%">
                <span class="muted">{{ __('Kund') }} {{ $customer['number'] ?? '' }}</span><br>
                <strong>{{ $customer['name'] ?? '' }}</strong><br>
                @if(! empty($customer['address_line1'])){{ $customer['address_line1'] }}<br>@endif
                @if(! empty($customer['address_line2'])){{ $customer['address_line2'] }}<br>@endif
                {{ trim(($customer['postal_code'] ?? '').' '.($customer['city'] ?? '')) }}
                @if(! empty($customer['vat_number']))<br><span class="muted">{{ __('Momsreg.nr') }} {{ $customer['vat_number'] }}</span>@endif
            </td>
            <td>
                @if($document->your_reference)<span class="muted">{{ __('Er referens') }}:</span> {{ $document->your_reference }}<br>@endif
                @if($document->our_reference)<span class="muted">{{ __('Vår referens') }}:</span> {{ $document->our_reference }}@endif
            </td>
        </tr>
    </table>

    <table class="rows" style="margin-top: 10mm">
        <thead>
            <tr>
                <th>{{ __('Benämning') }}</th>
                <th class="right">{{ __('Antal') }}</th>
                <th class="right">{{ __('Á-pris') }}</th>
                <th class="right">{{ __('Rabatt') }}</th>
                <th class="right">{{ __('Moms') }}</th>
                <th class="right">{{ __('Belopp') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($document->rows as $row)
                <tr>
                    <td>{{ $row->description }}</td>
                    <td class="right">{{ $qty($row->quantity) }} {{ $row->unit }}</td>
                    <td class="right">{{ $row->unit_price_ore->format(withCurrency: false) }}</td>
                    <td class="right">{{ $row->discount_bp ? str_replace('.', ',', (string) ($row->discount_bp / 100)).' %' : '' }}</td>
                    <td class="right">{{ $row->vat_rate / 100 }} %</td>
                    <td class="right">{{ $row->net_ore->format(withCurrency: false) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 6mm">
        <tr>
            <td style="width: 50%"></td>
            <td>
                <table class="sum">
                    <tr><td class="muted">{{ __('Summa exkl. moms') }}</td><td class="right">{{ $kr($totals['net']) }}</td></tr>
                    @foreach($totals['vat_by_rate'] as $rate => $group)
                        <tr><td class="muted">{{ __('Moms :rate % på :base', ['rate' => $rate / 100, 'base' => $kr($group['base'])]) }}</td><td class="right">{{ $kr($group['vat']) }}</td></tr>
                    @endforeach
                    @if($document->rounding_ore->ore !== 0)
                        <tr><td class="muted">{{ __('Öresavrundning') }}</td><td class="right">{{ $kr($document->rounding_ore->ore) }}</td></tr>
                    @endif
                    <tr class="total"><td>{{ __('Summa') }}</td><td class="right">{{ $document->total_ore->format() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if($document->notes)
        <p style="margin-top: 8mm">{!! nl2br(e($document->notes)) !!}</p>
    @endif


    <div class="footer">
        {{ $company->legal_name ?: $company->name }} · {{ __('Org.nr') }} {{ $company->org_number }}
        @if($company->vat_number) · {{ __('Momsreg.nr') }} {{ $company->vat_number }} @endif
        @if($company->f_skatt) · {{ __('Godkänd för F-skatt') }} @endif
        @if($company->email) · {{ $company->email }} @endif
        @if($company->phone) · {{ $company->phone }} @endif
    </div>
</body>
</html>
