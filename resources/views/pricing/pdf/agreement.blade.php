{{-- TODO(legal-verify): P-L5, whether an agreement signed through NONOX signering is valid as a written agreement between companies; the text below claims nothing beyond what the signing manual says. --}}
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Prisavtal :reference', ['reference' => $agreement->reference()]) }}</title>
<style>
    @page { margin: 18mm 16mm 22mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2933; }
    h1 { font-size: 18pt; margin: 0 0 2mm; letter-spacing: -0.3pt; }
    h2 { font-size: 11pt; margin: 8mm 0 2mm; }
    .muted { color: #616e7c; }
    .right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .meta td { padding: 1.2mm 0; border-bottom: 1px solid #e4e7eb; }
    .terms th { text-align: left; font-weight: normal; color: #616e7c; padding: 1.5mm 2mm 1.5mm 0; border-bottom: 1px solid #cbd2d9; }
    .terms td { padding: 1.5mm 2mm 1.5mm 0; border-bottom: 1px solid #e4e7eb; }
    .terms .num { text-align: right; }
    .box { border: 1px solid #cbd2d9; border-radius: 2mm; padding: 4mm; margin-top: 8mm; }
    @include('invoicing.partials.brand-style')
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
                <h1>{{ __('Prisavtal') }}</h1>
                <span class="muted">{{ $agreement->reference() }}</span>
            </td>
        </tr>
    </table>

    <h2>{{ $agreement->title }}</h2>

    <table class="meta">
        <tr><td class="muted" style="width: 40%">{{ __('Parter') }}</td><td>{{ $company->legal_name ?: $company->name }}@if($company->org_number) ({{ $company->org_number }})@endif {{ __('och') }} {{ $customer->name }}@if($customer->org_number) ({{ $customer->org_number }})@endif</td></tr>
        <tr><td class="muted">{{ __('Gäller från') }}</td><td>{{ $agreement->valid_from->toDateString() }}</td></tr>
        <tr><td class="muted">{{ __('Gäller till och med') }}</td><td>{{ $agreement->valid_to?->toDateString() ?? __('Tills vidare') }}</td></tr>
        <tr><td class="muted">{{ __('Valuta') }}</td><td>{{ __(':currency, priserna är exklusive moms', ['currency' => $agreement->currency]) }}</td></tr>
        <tr><td class="muted">{{ __('Kampanjer') }}</td><td>{{ $agreement->allow_campaigns ? __('En kampanj med lägre pris går före avtalets pris') : __('Avtalets pris gäller även under kampanjer') }}</td></tr>
        @if($supersedes)
            <tr><td class="muted">{{ __('Ersätter') }}</td><td>{{ __('Prisavtal :reference från och med :date', ['reference' => $supersedes->reference(), 'date' => $agreement->valid_from->toDateString()]) }}</td></tr>
        @endif
    </table>

    <h2>{{ __('Priser') }}</h2>
    <table class="terms">
        <thead>
            <tr>
                <th>{{ __('Artikel') }}</th>
                <th>{{ __('Enhet') }}</th>
                <th class="num">{{ __('Från antal') }}</th>
                <th class="num">{{ __('Pris eller rabatt') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $item->applies_to_all ? __('Alla artiklar') : $item->article?->number.' '.$item->article?->name }}</td>
                    <td>{{ $item->unit?->code ?? ($item->applies_to_all ? '' : $item->article?->unit) }}</td>
                    <td class="num">{{ $item->min_quantity_milli === 0 ? '' : App\Modules\Inventory\Domain\Quantity::format($item->min_quantity_milli) }}</td>
                    <td class="num">
                        @if($item->price_minor !== null)
                            {{ App\Support\Money\Money::ofOre($item->price_minor, $agreement->currency)->format() }}
                        @elseif($item->basePriceList)
                            {{ __(':percent % rabatt på prislistan :list', ['percent' => App\Domain\Pricing\PriceInput::basisPointsToPercent((int) $item->discount_bp), 'list' => $item->basePriceList->name]) }}
                        @else
                            {{ __(':percent % rabatt på ordinarie pris', ['percent' => App\Domain\Pricing\PriceInput::basisPointsToPercent((int) $item->discount_bp)]) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted">{{ __('Ett pris från ett visst antal gäller hela antalet på raden. Ordinarie pris är det pris säljaren tar av kunder utan avtal på den dag varan säljs.') }}</p>

    <div class="box">
        <span class="muted">{{ __('Avtalet signeras elektroniskt. Ett signeringsbevis med dokumentets kontrollsumma (SHA-256) hör till avtalet.') }}</span>
    </div>
</body>
</html>
