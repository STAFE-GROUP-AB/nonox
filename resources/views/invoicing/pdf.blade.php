@php
    use App\Support\Money\Money;

    $customer = $invoice->customer_snapshot ?? $invoice->customer?->snapshot() ?? [];
    $kr = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);
    $qty = fn (string $quantity): string => rtrim(rtrim(str_replace('.', ',', $quantity), '0'), ',');
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $invoice->title() }}</title>
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
    .rows td { border-bottom: 1px solid #e4e7eb; padding: 2mm 2mm 2mm 0; }
    .sum td { padding: 1mm 0; }
    .total td { font-size: 12pt; font-weight: bold; border-top: 2px solid #1f2933; padding-top: 2mm; }
    .box { border: 1px solid #cbd2d9; border-radius: 2mm; padding: 4mm; margin-top: 8mm; }
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
                <h1>{{ $invoice->isCredit() ? __('Kreditfaktura') : __('Faktura') }}</h1>
                <table class="meta">
                    <tr><td class="muted">{{ $invoice->isCredit() ? __('Kreditfakturanummer') : __('Fakturanummer') }}</td><td class="right">{{ $invoice->number ?? __('Utkast') }}</td></tr>
                    <tr><td class="muted">{{ __('Fakturadatum') }}</td><td class="right">{{ $invoice->invoice_date->toDateString() }}</td></tr>
                    @unless($invoice->isCredit())
                        <tr><td class="muted">{{ __('Förfallodag') }}</td><td class="right">{{ $invoice->due_date->toDateString() }}</td></tr>
                    @endunless
                    <tr><td class="muted">{{ __('Leveransdatum') }}</td><td class="right">{{ ($invoice->delivery_date ?? $invoice->invoice_date)->toDateString() }}</td></tr>
                    @if($invoice->ocr && ! $invoice->isCredit())
                        <tr><td class="muted">{{ __('OCR-nummer') }}</td><td class="right">{{ $invoice->ocr }}</td></tr>
                    @endif
                    @if($invoice->credits)
                        <tr><td class="muted">{{ __('Krediterar faktura') }}</td><td class="right">{{ $invoice->credits->number }}</td></tr>
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
                @if($invoice->your_reference)<span class="muted">{{ __('Er referens') }}:</span> {{ $invoice->your_reference }}<br>@endif
                @if($invoice->our_reference)<span class="muted">{{ __('Vår referens') }}:</span> {{ $invoice->our_reference }}@endif
            </td>
        </tr>
    </table>

    <table class="rows" style="margin-top: 10mm">
        <thead>
            <tr>
                <th>{{ __('Benämning') }}</th>
                <th class="right">{{ __('Antal') }}</th>
                <th class="right">{{ __('À-pris') }}</th>
                <th class="right">{{ __('Rabatt') }}</th>
                <th class="right">{{ __('Moms') }}</th>
                <th class="right">{{ __('Belopp') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->rows as $row)
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
                    @if($invoice->rounding_ore->ore !== 0)
                        <tr><td class="muted">{{ __('Öresavrundning') }}</td><td class="right">{{ $kr($invoice->rounding_ore->ore) }}</td></tr>
                    @endif
                    <tr class="total"><td>{{ $invoice->isCredit() ? __('Att kreditera') : __('Att betala') }}</td><td class="right">{{ $invoice->total_ore->format() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if($invoice->reverse_charge)
        <p style="margin-top: 8mm"><strong>{{ __('Omvänd betalningsskyldighet för byggtjänster.') }}</strong> {{ __('Köparen är skyldig att redovisa och betala momsen.') }}<br>
            <span class="muted">{{ __('Säljarens momsreg.nr :seller · Köparens momsreg.nr :buyer', ['seller' => $company->vat_number, 'buyer' => $customer['vat_number'] ?? '']) }}</span></p>
    @endif

    @if($invoice->notes)
        <p style="margin-top: 8mm">{!! nl2br(e($invoice->notes)) !!}</p>
    @endif

    @unless($invoice->isCredit())
        <div class="box">
            <strong>{{ __('Betalning') }}</strong><br>
            @if($company->bankgiro){{ __('Bankgiro') }} {{ $company->bankgiro }} · @endif
            @if($company->plusgiro){{ __('Plusgiro') }} {{ $company->plusgiro }} · @endif
            {{ __('Ange OCR-nummer :ocr', ['ocr' => $invoice->ocr ?? '–']) }} · {{ __('Senast :date', ['date' => $invoice->due_date->toDateString()]) }}
        </div>
    @endunless

    <div class="footer">
        {{ $company->legal_name ?: $company->name }} · {{ __('Org.nr') }} {{ $company->org_number }}
        @if($company->vat_number) · {{ __('Momsreg.nr') }} {{ $company->vat_number }} @endif
        @if($company->f_skatt) · {{ __('Godkänd för F-skatt') }} @endif
        @if($company->email) · {{ $company->email }} @endif
        @if($company->phone) · {{ $company->phone }} @endif
    </div>
</body>
</html>
