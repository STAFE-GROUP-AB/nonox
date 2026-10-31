@php
    $kr = fn (?int $ore): string => $ore === null ? '–' : number_format(intdiv($ore, 100), 0, ',', "\u{a0}");
    $year = $fiscalYear->label();
    $period = $fiscalYear->start_date->toDateString().' – '.$fiscalYear->end_date->toDateString();
    $signatories = collect($report->signatories)->filter(fn (array $person): bool => trim($person['name'] ?? '') !== '');
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Årsredovisning') }} {{ $company->legal_name ?: $company->name }} {{ $year }}</title>
<style>
    @page { margin: 22mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #17171a; line-height: 1.45; }
    h1 { font-size: 22pt; margin: 0 0 4pt; }
    h2 { font-size: 13pt; margin: 18pt 0 6pt; }
    h3 { font-size: 10.5pt; margin: 12pt 0 4pt; }
    p { margin: 0 0 6pt; }
    .muted { color: #6b6b70; }
    .cover { text-align: center; padding-top: 70mm; }
    .break { page-break-before: always; }
    table { width: 100%; border-collapse: collapse; margin: 4pt 0 10pt; }
    th { text-align: left; font-weight: normal; color: #6b6b70; border-bottom: 0.8pt solid #17171a; padding: 3pt 4pt; font-size: 8.5pt; }
    td { padding: 2.5pt 4pt; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .note { width: 30pt; text-align: center; color: #6b6b70; }
    tr.sum td { font-weight: bold; border-top: 0.6pt solid #17171a; }
    tr.head td { font-weight: bold; padding-top: 8pt; }
    tr.total td { font-weight: bold; border-top: 1.2pt double #17171a; }
    .signatures td { padding-top: 34pt; width: 50%; }
    .line { border-top: 0.6pt solid #17171a; padding-top: 3pt; display: block; width: 80%; }
</style>
</head>
<body>
    <div class="cover">
        <h1>{{ __('Årsredovisning') }}</h1>
        <p style="font-size: 13pt;">{{ __('för') }}</p>
        <p style="font-size: 15pt; font-weight: bold;">{{ $company->legal_name ?: $company->name }}</p>
        <p>{{ $company->org_number }}</p>
        <p style="margin-top: 14pt;">{{ __('Räkenskapsåret :period', ['period' => $period]) }}</p>
    </div>

    <div class="break">
        <p class="muted">{{ __('Styrelsen avger följande årsredovisning för räkenskapsåret :period.', ['period' => $period]) }}</p>
        <p class="muted">{{ __('Om inte annat särskilt anges redovisas alla belopp i hela kronor.') }}</p>

        <h2>{{ __('Förvaltningsberättelse') }}</h2>
        <h3>{{ __('Allmänt om verksamheten') }}</h3>
        <p>{!! nl2br(e($report->business_description ?: __('Beskriv verksamheten och var företaget har sitt säte.'))) !!}</p>

        @if(filled($report->significant_events))
            <h3>{{ __('Väsentliga händelser under räkenskapsåret') }}</h3>
            <p>{!! nl2br(e($report->significant_events)) !!}</p>
        @endif

        <h3>{{ __('Flerårsöversikt') }}</h3>
        <table>
            <thead><tr><th></th>@foreach($multiYear as $row)<th class="num">{{ $row['label'] }}</th>@endforeach</tr></thead>
            <tbody>
                <tr><td>{{ __('Nettoomsättning') }}</td>@foreach($multiYear as $row)<td class="num">{{ $kr($row['net_sales']) }}</td>@endforeach</tr>
                <tr><td>{{ __('Resultat efter finansiella poster') }}</td>@foreach($multiYear as $row)<td class="num">{{ $kr($row['after_financial']) }}</td>@endforeach</tr>
                <tr><td>{{ __('Soliditet (%)') }}</td>@foreach($multiYear as $row)<td class="num">{{ $row['solidity'] === null ? '–' : number_format($row['solidity'], 1, ',', '') }}</td>@endforeach</tr>
            </tbody>
        </table>

        <h3>{{ __('Förändringar i eget kapital') }}</h3>
        <table>
            <thead><tr><th></th><th class="num">{{ __('Vid årets början') }}</th><th class="num">{{ __('Vid årets slut') }}</th></tr></thead>
            <tbody>
                @foreach($equityChanges as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="num">{{ $kr($row['opening']) }}</td><td class="num">{{ $kr($row['closing']) }}</td></tr>
                @endforeach
                <tr class="sum"><td>{{ __('Summa eget kapital') }}</td><td class="num">{{ $kr(array_sum(array_column($equityChanges, 'opening'))) }}</td><td class="num">{{ $kr(array_sum(array_column($equityChanges, 'closing'))) }}</td></tr>
            </tbody>
        </table>

        <h3>{{ __('Resultatdisposition') }}</h3>
        <p>{{ __('Styrelsen föreslår att till förfogande stående medel, :amount kr, disponeras enligt följande:', ['amount' => $kr($disposition['free'])]) }}</p>
        <table>
            <tbody>
                @if($disposition['dividend'] > 0)
                    <tr><td>{{ __('Utdelas till aktieägarna') }}</td><td class="num">{{ $kr($disposition['dividend']) }}</td></tr>
                @endif
                <tr><td>{{ __('Balanseras i ny räkning') }}</td><td class="num">{{ $kr($disposition['carried']) }}</td></tr>
                <tr class="sum"><td>{{ __('Summa') }}</td><td class="num">{{ $kr($disposition['free']) }}</td></tr>
            </tbody>
        </table>
        <p>{{ __('Företagets resultat och ställning i övrigt framgår av efterföljande resultat- och balansräkning med noter.') }}</p>
    </div>

    <div class="break">
        <h2>{{ __('Resultaträkning') }}</h2>
        <table>
            <thead><tr><th></th><th class="note">{{ __('Not') }}</th><th class="num">{{ $period }}</th>@if($hasPreviousIncome)<th class="num">{{ $previousLabel }}</th>@endif</tr></thead>
            <tbody>
                @foreach($income as $line)
                    <tr @class(['sum' => $line['subtotal']])>
                        <td>{{ $line['label'] }}</td>
                        <td class="note">@if($line['key'] === 'personnel'){{ 2 }}@endif</td>
                        <td class="num">{{ $kr($line['amount']) }}</td>
                        @if($hasPreviousIncome)<td class="num">{{ $kr($line['previous']) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="break">
        <h2>{{ __('Balansräkning') }}</h2>
        @php($fixedNote = 3)
        @php($assetsDone = false)
        <table>
            <thead><tr><th></th><th class="note">{{ __('Not') }}</th><th class="num">{{ $fiscalYear->end_date->toDateString() }}</th><th class="num">{{ $fiscalYear->start_date->subDay()->toDateString() }}</th></tr></thead>
            <tbody>
                <tr class="head"><td colspan="4">{{ __('Tillgångar') }}</td></tr>
                @foreach($balance['sections'] as $section)
                    @if(! $assetsDone && ! in_array($section['key'], ['fixed', 'current'], true))
                        @php($assetsDone = true)
                        <tr class="total"><td>{{ __('Summa tillgångar') }}</td><td></td><td class="num">{{ $kr($balance['assets']) }}</td><td class="num">{{ $kr($balance['assets_previous']) }}</td></tr>
                        <tr class="head"><td colspan="4">{{ __('Eget kapital och skulder') }}</td></tr>
                    @endif
                    <tr class="head"><td colspan="4">{{ $section['label'] }}</td></tr>
                    @foreach($section['lines'] as $line)
                        <tr>
                            <td>{{ $line['label'] }}</td>
                            <td class="note">@if(in_array($line['key'], ['intangible', 'buildings', 'machinery'], true) && $fixedAssets !== []){{ $fixedNote }}@endif</td>
                            <td class="num">{{ $kr($line['amount']) }}</td>
                            <td class="num">{{ $kr($line['previous']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="sum"><td>{{ __('Summa :section', ['section' => mb_strtolower($section['label'])]) }}</td><td></td><td class="num">{{ $kr($section['amount']) }}</td><td class="num">{{ $kr($section['previous']) }}</td></tr>
                @endforeach
                <tr class="total"><td>{{ __('Summa eget kapital och skulder') }}</td><td></td><td class="num">{{ $kr($balance['equity_liabilities']) }}</td><td class="num">{{ $kr($balance['equity_liabilities_previous']) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="break">
        <h2>{{ __('Noter') }}</h2>
        <h3>{{ __('Not 1 Redovisningsprinciper') }}</h3>
        <p>{{ __('Årsredovisningen har upprättats i enlighet med årsredovisningslagen och Bokföringsnämndens allmänna råd (BFNAR 2016:10) om årsredovisning i mindre företag.') }}</p>
        @if($depreciation !== [])
            <p>{{ __('Materiella anläggningstillgångar skrivs av linjärt över den bedömda nyttjandeperioden:') }} {{ implode(', ', $depreciation) }}.</p>
        @endif

        <h3>{{ __('Not 2 Medelantalet anställda') }}</h3>
        <table>
            <thead><tr><th></th><th class="num">{{ $year }}</th></tr></thead>
            <tbody><tr><td>{{ __('Medelantalet anställda') }}</td><td class="num">{{ $report->average_employees }}</td></tr></tbody>
        </table>

        @foreach($fixedAssets as $note)
            <h3>{{ __('Not :number :label', ['number' => 3 + $loop->index, 'label' => $note['label']]) }}</h3>
            <table>
                <thead><tr><th></th><th class="num">{{ $fiscalYear->end_date->toDateString() }}</th></tr></thead>
                <tbody>
                    <tr><td>{{ __('Ingående anskaffningsvärden') }}</td><td class="num">{{ $kr($note['acquisition']['opening']) }}</td></tr>
                    <tr><td>{{ __('Inköp') }}</td><td class="num">{{ $kr($note['acquisition']['additions']) }}</td></tr>
                    <tr><td>{{ __('Försäljningar och utrangeringar') }}</td><td class="num">{{ $kr(-$note['acquisition']['disposals']) }}</td></tr>
                    <tr class="sum"><td>{{ __('Utgående ackumulerade anskaffningsvärden') }}</td><td class="num">{{ $kr($note['acquisition']['closing']) }}</td></tr>
                    <tr><td>{{ __('Ingående avskrivningar') }}</td><td class="num">{{ $kr(-$note['depreciation']['opening']) }}</td></tr>
                    <tr><td>{{ __('Försäljningar och utrangeringar') }}</td><td class="num">{{ $kr($note['depreciation']['disposals']) }}</td></tr>
                    <tr><td>{{ __('Årets avskrivningar') }}</td><td class="num">{{ $kr(-$note['depreciation']['charge']) }}</td></tr>
                    <tr class="sum"><td>{{ __('Utgående ackumulerade avskrivningar') }}</td><td class="num">{{ $kr(-$note['depreciation']['closing']) }}</td></tr>
                    <tr class="total"><td>{{ __('Utgående redovisat värde') }}</td><td class="num">{{ $kr($note['book_value']) }}</td></tr>
                </tbody>
            </table>
        @endforeach

        <h2 style="margin-top: 26pt;">{{ __('Underskrifter') }}</h2>
        <p>{{ $report->signing_place ?: '……………………' }}, {{ $report->signing_date?->toDateString() ?? '……………………' }}</p>
        <table class="signatures">
            <tbody>
                @foreach($signatories->chunk(2) as $pair)
                    <tr>
                        @foreach($pair as $person)
                            <td><span class="line">{{ $person['name'] }}</span><span class="muted">{{ $person['role'] ?? '' }}</span></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
