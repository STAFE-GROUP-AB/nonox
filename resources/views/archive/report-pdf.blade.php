<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; color: #111; }
        h1 { font-size: 13pt; margin: 0 0 2pt; }
        .meta { color: #555; margin-bottom: 10pt; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; border-bottom: 1px solid #333; padding: 3pt 4pt; font-weight: bold; }
        td { border-bottom: 1px solid #e5e5e5; padding: 2pt 4pt; vertical-align: top; }
        td.amount, th.amount { text-align: right; white-space: nowrap; }
        .empty { color: #555; padding-top: 8pt; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        {{ $company->name }} · {{ $company->org_number }} ·
        {{ __('Räkenskapsår :start – :end', ['start' => $fiscalYear->start_date->toDateString(), 'end' => $fiscalYear->end_date->toDateString()]) }}
        @if($parts > 1) · {{ __('Del :part av :parts', ['part' => $part, 'parts' => $parts]) }}@endif
    </div>
    @if($rows === [])
        <p class="empty">{{ __('Inga bokförda belopp under året.') }}</p>
    @else
        @php
            $amountColumns = collect($rows)->flatMap(fn ($row) => collect($row)->filter(fn ($cell) => is_string($cell) && preg_match('/^-?\d+,\d{2}$/', $cell) === 1)->keys())->unique()->all();
        @endphp
        <table>
            <thead>
                <tr>
                    @foreach($heading as $index => $cell)<th class="{{ in_array($index, $amountColumns, true) ? 'amount' : '' }}">{{ $cell }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        @foreach($row as $index => $cell)<td class="{{ in_array($index, $amountColumns, true) ? 'amount' : '' }}">{{ $cell }}</td>@endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
