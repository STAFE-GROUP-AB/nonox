<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $table->title }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; line-height: 1.35; color: #1f2933; margin: 12mm; }
    h1 { font-size: 14pt; margin: 0 0 1mm; }
    .muted { color: #616e7c; }
    table { width: 100%; border-collapse: collapse; margin-top: 5mm; }
    th { text-align: right; font-size: 7pt; color: #616e7c; border-bottom: 0.3mm solid #9aa5b1; padding: 1.2mm 1mm; }
    th:first-child, td:first-child { text-align: left; }
    td { border-bottom: 0.2mm solid #e4e7eb; padding: 1.2mm 1mm; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    tr.total td { font-weight: bold; border-top: 0.3mm solid #9aa5b1; border-bottom: none; }
</style>
</head>
<body>
    <p class="muted">{{ $company->name }} · {{ $company->org_number }}</p>
    <h1>{{ $table->title }}</h1>
    <p class="muted">{{ $table->subtitle }}</p>
    <table>
        <thead>
            <tr>
                @foreach($table->header as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $index => $row)
                <tr @class(['total' => $loop->last])>
                    @foreach($row as $cell)
                        <td @class(['num' => $cell['figure']])>{{ $cell['text'] }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
