@php
    use App\Support\Money\Money;

    $f = fn (?int $ore): string => $ore === null ? '' : Money::ofOre($ore)->format();
    $detailed = $report['detailed'];
    $span = $detailed ? 4 : 2;
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $report['label'] }}</title>
<style>
    @page { margin: 16mm 14mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.4pt; line-height: 1.35; color: #1f2933; }
    h1 { font-size: 14pt; margin: 0 0 1mm; }
    h2 { font-size: 11pt; margin: 6mm 0 1mm; }
    .label { border: 0.3mm solid #b7791f; background: #fffbeb; color: #744210; padding: 2mm 3mm; font-weight: bold; }
    .muted { color: #616e7c; }
    .warning { color: #9b2c2c; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
    th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #616e7c; border-bottom: 0.3mm solid #9aa5b1; padding: 1.2mm 1mm; }
    td { border-bottom: 0.2mm solid #e4e7eb; padding: 1mm; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .group td { font-weight: bold; padding-top: 2.5mm; border-bottom: none; }
    .sum td { font-weight: bold; border-top: 0.3mm solid #9aa5b1; }
    .total td { font-weight: bold; border-top: 0.4mm solid #1f2933; }
    footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #616e7c; border-top: 0.2mm solid #cbd2d9; padding-top: 1.5mm; }
</style>
</head>
<body>
    {{-- ADR-060 2.4 and S2-R14: the label on every page, not only the first. --}}
    <footer>{{ $report['label'] }} · {{ $report['group']->name }} · {{ $report['from'] }} – {{ $report['to'] }} · {{ __('Körning nr :id', ['id' => $report['run_id']]) }}</footer>

    <p class="label">{{ $report['label'] }}</p>
    <h1>{{ __('Intern koncernrapport :from – :to', ['from' => $report['from'], 'to' => $report['to']]) }}</h1>
    <p class="muted">{{ $report['group']->name }} · {{ implode(', ', $report['members']) }}</p>
    <p class="muted">
        {{ __('Körning nr :id, gjord :when', ['id' => $report['run_id'], 'when' => $report['created_at']->timezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}
        · {{ $report['preliminary'] ? __('Preliminär') : __('Låsta perioder') }}
        · {{ __('Kontrollsumma') }} {{ substr($report['output_hash'], 0, 16) }}
    </p>
    <p class="muted">{{ __('Rapporterna visar en sparad konsolidering: företagens egen bokföring summerad efter koncernens kontomappning, med de elimineringar som är godkända i koncernjournalen för samma period. De är ett internt underlag och inte en koncernredovisning, och ingenting bokförs i något av företagen.') }}</p>
    @if(! $report['intact'] || ! $report['entries_intact'] || ! $report['consistent'])
        <p class="warning">{{ __('Körningen eller koncernjournalen stämmer inte med sina kontrollsummor. Använd inte siffrorna och kontakta den som driver NONOX.') }}</p>
    @endif
    @if($report['preliminary'])
        <p>{{ __('Körningen är preliminär: minst ett av företagen har inte låst perioden eller stängt räkenskapsåret, så siffrorna kan ändras.') }}</p>
    @endif

    <h2>{{ __('Resultaträkning') }}</h2>
    <table>
        <thead><tr>
            <th>{{ __('Konto') }}</th>
            @if($detailed)<th class="num">{{ __('Företagen') }}</th><th class="num">{{ __('Elimineringar') }}</th>@endif
            <th class="num">{{ __('Koncernen') }}</th>
        </tr></thead>
        <tbody>
            @foreach($report['income']['groups'] as $group)
                @if($group['rows'] !== [])
                    <tr class="group"><td colspan="{{ $span }}">{{ $group['label'] }}</td></tr>
                    @foreach($group['rows'] as $row)
                        <tr><td>{{ $row['number'] }} {{ $row['name'] }}</td>@if($detailed)<td class="num">{{ $f($row['companies']) }}</td><td class="num">{{ $f($row['eliminations']) }}</td>@endif<td class="num">{{ $f($row['group']) }}</td></tr>
                    @endforeach
                    <tr class="sum"><td>{{ __('Summa :group', ['group' => mb_strtolower($group['label'])]) }}</td>@if($detailed)<td class="num">{{ $f($group['total']['companies']) }}</td><td class="num">{{ $f($group['total']['eliminations']) }}</td>@endif<td class="num">{{ $f($group['total']['group']) }}</td></tr>
                @endif
                @if($group['subtotal'])
                    <tr class="sum"><td>{{ $group['subtotal']['label'] }}</td>@if($detailed)<td class="num">{{ $f($group['subtotal']['companies']) }}</td><td class="num">{{ $f($group['subtotal']['eliminations']) }}</td>@endif<td class="num">{{ $f($group['subtotal']['group']) }}</td></tr>
                @endif
            @endforeach
            <tr class="total"><td>{{ __('Periodens resultat') }}</td>@if($detailed)<td class="num">{{ $f($report['income']['result']['companies']) }}</td><td class="num">{{ $f($report['income']['result']['eliminations']) }}</td>@endif<td class="num">{{ $f($report['income']['result']['group']) }}</td></tr>
        </tbody>
    </table>

    <h2>{{ __('Balansräkning :date', ['date' => $report['to']]) }}</h2>
    <table>
        <thead><tr>
            <th>{{ __('Konto') }}</th>
            @if($detailed)<th class="num">{{ __('Företagen') }}</th><th class="num">{{ __('Elimineringar') }}</th>@endif
            <th class="num">{{ __('Koncernen') }}</th>
        </tr></thead>
        <tbody>
            @foreach($report['balance'] as $sideKey => $side)
                <tr class="group"><td colspan="{{ $span }}">{{ $side['label'] }}</td></tr>
                @foreach($side['groups'] as $group)
                    @foreach($group['rows'] as $row)
                        <tr><td>{{ $row['number'] }} {{ $row['name'] }}</td>@if($detailed)<td class="num">{{ $f($row['companies']) }}</td><td class="num">{{ $f($row['eliminations']) }}</td>@endif<td class="num">{{ $f($row['group']) }}</td></tr>
                    @endforeach
                @endforeach
                @if($sideKey === 'equity')
                    <tr><td>{{ __('Årets resultat som ännu inte är bokfört mot eget kapital') }}</td>@if($detailed)<td class="num">{{ $f($side['unbooked']['companies']) }}</td><td class="num">{{ $f($side['unbooked']['eliminations']) }}</td>@endif<td class="num">{{ $f($side['unbooked']['group']) }}</td></tr>
                @endif
                <tr class="total"><td>{{ __('Summa :side', ['side' => mb_strtolower($side['label'])]) }}</td>@if($detailed)<td class="num">{{ $f($side['total']['companies']) }}</td><td class="num">{{ $f($side['total']['eliminations']) }}</td>@endif<td class="num">{{ $f($side['total']['group']) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted">{{ __('Beloppen summeras konto för konto över företagen, som är olika juridiska personer. En skattefordran i ett företag kan därför minska en skatteskuld i ett annat, och ett negativt banksaldo i ett företag kan minska ett annat företags bankmedel. I företagens egna redovisningar kvittas sådana belopp inte.') }}</p>

    <h2>{{ __('Elimineringar per slag') }}</h2>
    @if($report['eliminations'] === [])
        <p>{{ __('Inga elimineringar är godkända för den här perioden. Siffrorna är företagens summor.') }}</p>
    @else
        <table>
            <tbody>
                @foreach($report['eliminations'] as $elimination)
                    <tr><td>{{ $elimination['label'] }}</td><td class="num">{{ $elimination['amount'] === null ? trans_choice('{1} en post|[2,*] :count poster', $elimination['entries'], ['count' => $elimination['entries']]) : $f($elimination['amount']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <p class="muted">{{ __('Kontrollsumma för indata: :hash', ['hash' => $report['inputs_hash']]) }}<br>{{ __('Kontrollsumma för resultatet: :hash', ['hash' => $report['output_hash']]) }}</p>
</body>
</html>
