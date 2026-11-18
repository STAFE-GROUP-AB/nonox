@php
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
    $cm = fn (?int $mm): string => $mm === null ? '–' : rtrim(rtrim(number_format($mm / 10, 1, ',', ''), '0'), ',');
    $totalGrams = $shipment->packages->sum(fn ($package): int => (int) $package->weight_grams);
    $anonymised = $shipment->ship_to === null && $shipment->customer !== null && ! $shipment->customer->is_active && (str_starts_with((string) $shipment->customer->name, 'Anonymiserad kund') || str_starts_with((string) $shipment->customer->name, 'Anonymised customer'));
    $recipient = $shipment->ship_to ?? ($shipment->status !== 'DRAFT' ? '' : collect([$shipment->customer?->name, $shipment->customer?->address_line1, $shipment->customer?->address_line2, trim($shipment->customer?->postal_code.' '.$shipment->customer?->city)])->filter(fn ($line): bool => trim((string) $line) !== '')->implode("\n"));
    $recipient = $anonymised || trim($recipient) === '' ? __('Mottagare saknas') : $recipient;
@endphp
<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title>{{ __('Följesedel :number', ['number' => $shipment->number]) }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1c1917; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 18px 0 4px; }
        p { margin: 0 0 6px; color: #57534e; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; color: #57534e; border-bottom: 1px solid #a8a29e; padding: 4px; }
        td { border-bottom: 1px solid #e7e5e4; padding: 6px 4px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .meta td { border: 0; padding: 2px 4px; }
        .recipient { font-size: 13px; color: #1c1917; white-space: pre-line; }
    </style>
</head>
<body>
    <h1>{{ __('Följesedel') }} {{ $shipment->number }}</h1>
    <p>{{ $company->name }}@if($company->address_line1) · {{ $company->address_line1 }}, {{ $company->postal_code }} {{ $company->city }}@endif</p>
    @if($shipment->status !== 'SHIPPED')<p><strong>{{ __('Ej skickad än') }}</strong></p>@endif

    <table class="meta">
        <tr>
            <td style="width: 50%">
                <strong>{{ __('Mottagare') }}</strong>
                <div class="recipient">{{ $recipient }}</div>
            </td>
            <td>
                <div>{{ __('Datum') }}: {{ ($shipment->shipped_at ?? $shipment->created_at)->toDateString() }}</div>
                @if($shipment->salesDocument)<div>{{ __('Order :number', ['number' => $shipment->salesDocument->number]) }}</div>
                @elseif($shipment->pickList?->source_label)<div>{{ $shipment->pickList->source_label }}</div>@endif
                @if($shipment->carrier)<div>{{ __('Transportör') }}: {{ $shipment->carrier }}</div>@endif
                @if($shipment->tracking_number)<div>{{ __('Spårningsnummer') }}: {{ $shipment->tracking_number }}</div>@endif
                <div>{{ __('Kollin') }}: {{ $shipment->packages->count() }}@if($totalGrams > 0) · {{ number_format($totalGrams / 1000, 3, ',', "\u{00A0}") }} kg @endif</div>
            </td>
        </tr>
    </table>

    @foreach($shipment->packages as $package)
        <h2>{{ __('Kolli :number', ['number' => $package->position]) }}
            <span style="font-weight: normal; color: #57534e;">
                @if($package->weight_grams !== null) · {{ number_format($package->weight_grams / 1000, 3, ',', "\u{00A0}") }} kg @endif
                @if($package->length_mm !== null || $package->width_mm !== null || $package->height_mm !== null) · {{ $cm($package->length_mm) }} × {{ $cm($package->width_mm) }} × {{ $cm($package->height_mm) }} cm @endif
            </span>
        </h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Artikel') }}</th>
                    <th>{{ __('Batch / serienummer') }}</th>
                    <th class="num">{{ __('Antal') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($package->lines as $line)
                    <tr>
                        <td>{{ $line->article?->number }} {{ $line->article?->name }}</td>
                        <td>@if($line->lot){{ $line->lot->code }}@if($line->lot->expires_on) · {{ __('bäst före :date', ['date' => $line->lot->expires_on->toDateString()]) }}@endif @endif</td>
                        <td class="num">{{ $q($line->quantity_milli) }} {{ $line->article?->stockUnitCode() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>
