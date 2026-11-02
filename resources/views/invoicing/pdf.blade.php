@php
    use App\Support\Money\Money;

    $customer = $invoice->customer_snapshot ?? $invoice->customer?->snapshot() ?? [];
    $kr = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);
    $foreign = $invoice->isForeign();
    // An invoice in another currency shows its rows in that currency; kronor are shown below the totals.
    $price = fn ($row): string => $foreign ? $kr((int) $row->foreign_unit_price_minor) : $row->unit_price_ore->format(withCurrency: false);
    $rowNet = fn ($row): string => $foreign ? $kr((int) $row->foreign_net_minor) : $row->net_ore->format(withCurrency: false);
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
                    @if($foreign)
                        <tr><td class="muted">{{ __('Valuta') }}</td><td class="right">{{ $invoice->invoice_currency }}</td></tr>
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
                    <td class="right">{{ $price($row) }}</td>
                    <td class="right">{{ $row->discount_bp ? str_replace('.', ',', (string) ($row->discount_bp / 100)).' %' : '' }}</td>
                    <td class="right">{{ $row->vat_rate / 100 }} %</td>
                    <td class="right">{{ $rowNet($row) }}</td>
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
                    @if($invoice->tax_reduction !== null)
                        <tr><td class="muted">{{ __('Summa inkl. moms') }}</td><td class="right">{{ $kr($invoice->total_ore->ore + $invoice->tax_reduction_ore->ore) }}</td></tr>
                        <tr><td class="muted">{{ __('Avgår :type', ['type' => App\Domain\Invoicing\TaxReduction::label($invoice->tax_reduction)]) }}</td><td class="right">{{ $kr(-$invoice->tax_reduction_ore->ore) }}</td></tr>
                    @endif
                    <tr class="total"><td>{{ $invoice->isCredit() ? __('Att kreditera') : __('Att betala') }}</td><td class="right">{{ $foreign ? $invoice->formatForeign((int) $invoice->foreign_total_minor) : $invoice->total_ore->format() }}</td></tr>
                </table>
                @if($foreign && $kronor !== null)
                    <table class="sum" style="margin-top: 4mm">
                        <tr><td colspan="2"><strong>{{ __('I svenska kronor') }}</strong></td></tr>
                        @foreach($kronor['vat_by_rate'] as $rate => $group)
                            <tr><td class="muted">{{ __('Moms :rate % på :base kr', ['rate' => $rate / 100, 'base' => $kr($group['base'])]) }}</td><td class="right">{{ $kr($group['vat']) }} kr</td></tr>
                        @endforeach
                        <tr><td class="muted">{{ __('Summa inkl. moms') }}</td><td class="right">{{ $invoice->total_ore->format() }}</td></tr>
                        <tr><td class="muted" colspan="2">{{ $invoice->exchange_rate_source === 'riksbank'
                            ? __('Kurs :rate kr per :currency, Riksbankens mittkurs :date.', ['rate' => $invoice->bookingRate()?->format(), 'currency' => $invoice->invoice_currency, 'date' => $invoice->exchange_rate_date?->toDateString()])
                            : __('Kurs :rate kr per :currency.', ['rate' => $invoice->bookingRate()?->format(), 'currency' => $invoice->invoice_currency]) }}</td></tr>
                    </table>
                @endif
            </td>
        </tr>
    </table>

    @if($invoice->tax_reduction !== null)
        @php($labour = $invoice->rows->where('is_labour', true))
        <p style="margin-top: 8mm"><strong>{{ App\Domain\Invoicing\TaxReduction::label($invoice->tax_reduction) }}.</strong>
            {{ __('Arbetskostnad inklusive moms :labour. Avdraget förutsätter att Skatteverket godkänner det; annars betalar köparen resten.', ['labour' => $kr((int) round($labour->sum(fn ($row) => $row->net_ore->ore * (10000 + $row->vat_rate) / 10000)))]) }}
            @if($invoice->property_designation)<br><span class="muted">{{ __('Fastighet eller bostadsrätt: :property', ['property' => $invoice->property_designation]) }}</span>@endif</p>
    @endif

    @if($invoice->reverse_charge)
        <p style="margin-top: 8mm"><strong>{{ __('Omvänd betalningsskyldighet för byggtjänster.') }}</strong> {{ __('Köparen är skyldig att redovisa och betala momsen.') }}<br>
            <span class="muted">{{ __('Säljarens momsreg.nr :seller · Köparens momsreg.nr :buyer', ['seller' => $company->vat_number, 'buyer' => $customer['vat_number'] ?? '']) }}</span></p>
    @endif

    @if($invoice->bookedForeignSale() !== null)
        <p style="margin-top: 8mm"><strong>{{ $invoice->bookedForeignSale()->invoiceText() }}</strong><br>
            <span class="muted">@if($company->vat_number){{ __('Säljarens momsreg.nr :seller', ['seller' => $company->vat_number]) }}@endif @if(filled($customer['vat_number'] ?? null)) · {{ __('Köparens momsreg.nr :buyer', ['buyer' => $customer['vat_number']]) }}@endif</span></p>
    @endif

    @php($exemptRows = $invoice->rows->filter(fn ($row) => filled($row->vat_exemption_text))->groupBy('vat_exemption_text'))
    @if($exemptRows->isNotEmpty())
        <p style="margin-top: 8mm"><strong>{{ __('Utan moms') }}</strong>
            @foreach($exemptRows as $text => $group)
                <br>{{ $text }} {{ __('Underlag :base.', ['base' => $kr((int) $group->sum(fn ($row) => $foreign ? (int) $row->foreign_net_minor : $row->net_ore->ore))]) }}
            @endforeach
        </p>
    @endif

    @if($invoice->vat_exemption_text)
        <p style="margin-top: 8mm"><strong>{{ $invoice->vat_exemption_text }}</strong></p>
    @endif

    @if($invoice->notes)
        <p style="margin-top: 8mm">{!! nl2br(e($invoice->notes)) !!}</p>
    @endif

    @unless($invoice->isCredit())
        <div class="box">
            <strong>{{ __('Betalning') }}</strong><br>
            @if($foreign && $company->iban){{ __('IBAN') }} {{ $company->iban }} · @if($company->bic){{ __('BIC') }} {{ $company->bic }} · @endif @endif
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
