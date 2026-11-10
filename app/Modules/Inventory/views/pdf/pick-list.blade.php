@php($q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli))
<!doctype html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title>{{ __('Plocklista :number', ['number' => $list->number]) }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1c1917; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p { margin: 0 0 12px; color: #57534e; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; color: #57534e; border-bottom: 1px solid #a8a29e; padding: 4px; }
        td { border-bottom: 1px solid #e7e5e4; padding: 6px 4px; vertical-align: top; }
        .loc { font-size: 15px; font-weight: bold; white-space: nowrap; }
        .num { text-align: right; white-space: nowrap; }
        .box { width: 16px; height: 16px; border: 1px solid #1c1917; }
    </style>
</head>
<body>
    <h1>{{ __('Plocklista :number', ['number' => $list->number]) }}</h1>
    <p>{{ $company->name }} · {{ $list->source_label }} {{ $list->customer?->name }} · {{ $list->created_at->toDateString() }}</p>
    <table>
        <thead>
            <tr>
                <th>{{ __('Plats') }}</th>
                <th>{{ __('Artikel') }}</th>
                <th class="num">{{ __('Antal') }}</th>
                <th>{{ __('Plockat') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($list->lines as $line)
                <tr>
                    <td class="loc">{{ $line->location?->full_code ?? '–' }}</td>
                    <td>{{ $line->article?->number }} {{ $line->article?->name }}@if($line->article?->gtin)<br>EAN {{ $line->article->gtin }}@endif</td>
                    <td class="num">{{ $q($line->quantity_milli) }} {{ $line->article?->stockUnitCode() }}</td>
                    <td><div class="box"></div></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
