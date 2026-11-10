@php
    $kr = fn (?int $ore): string => $ore === null ? '–' : number_format(intdiv($ore, 100), 0, ',', "\u{a0}");
    $year = $fiscalYear->label();
    $period = $fiscalYear->start_date->toDateString().' – '.$fiscalYear->end_date->toDateString();
    $closing = $fiscalYear->end_date->toDateString();
    $opening = $fiscalYear->start_date->subDay()->toDateString();
    $signatories = collect($report->signatories)->filter(fn (array $person): bool => trim($person['name'] ?? '') !== '');
    $name = $company->legal_name ?: $company->name;
    $sections = collect($balance['sections'])->keyBy('key');
    $assetGroups = ['fixed', 'current'];
    $cashFlowSections = ['operating' => __('Den löpande verksamheten'), 'investing' => __('Investeringsverksamheten'), 'financing' => __('Finansieringsverksamheten')];
    $principles = array_values(array_filter($notes, fn ($note): bool => $note->section === \App\Domain\AnnualReport\K3Notes::PRINCIPLES));
    $otherNotes = array_values(array_filter($notes, fn ($note): bool => $note->section !== \App\Domain\AnnualReport\K3Notes::PRINCIPLES));
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Årsredovisning') }} {{ $name }} {{ $year }}</title>
<style>
    @page { margin: 22mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #17171a; line-height: 1.45; }
    h1 { font-size: 22pt; margin: 0 0 4pt; }
    h2 { font-size: 13pt; margin: 18pt 0 6pt; }
    h3 { font-size: 10.5pt; margin: 12pt 0 4pt; }
    h4 { font-size: 9.5pt; margin: 8pt 0 3pt; }
    p { margin: 0 0 6pt; }
    .muted { color: #5c5c61; }
    .cover { text-align: center; padding-top: 70mm; }
    .break { page-break-before: always; }
    .draft { border: 1pt solid #8a5a00; color: #5c3b00; padding: 6pt 8pt; margin-bottom: 10pt; }
    .override { font-size: 8pt; color: #5c3b00; }
    table { width: 100%; border-collapse: collapse; margin: 4pt 0 10pt; }
    th { text-align: left; font-weight: normal; color: #5c5c61; border-bottom: 0.8pt solid #17171a; padding: 3pt 4pt; font-size: 8.5pt; }
    th[scope="row"] { color: #17171a; border-bottom: 0; font-size: 9.5pt; padding: 2.5pt 4pt; }
    td { padding: 2.5pt 4pt; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .note { width: 30pt; text-align: center; color: #5c5c61; }
    .spec th[scope="row"] { padding-left: 14pt; font-style: italic; }
    tr.sum th, tr.sum td { font-weight: bold; border-top: 0.6pt solid #17171a; }
    tr.head th { font-weight: bold; padding-top: 8pt; color: #17171a; border-bottom: 0; font-size: 9.5pt; }
    tr.total th, tr.total td { font-weight: bold; border-top: 1.2pt double #17171a; }
    .signatures td { padding-top: 34pt; width: 50%; }
    .line { border-top: 0.6pt solid #17171a; padding-top: 3pt; display: block; width: 80%; }
</style>
</head>
<body>
<main>
    <div class="cover">
        <h1>{{ __('Årsredovisning') }}</h1>
        <p style="font-size: 13pt;">{{ __('för') }}</p>
        <p style="font-size: 15pt; font-weight: bold;">{{ $name }}</p>
        <p>{{ $company->org_number }}</p>
        <p style="margin-top: 14pt;">{{ __('Räkenskapsåret :period', ['period' => $period]) }}</p>
    </div>

    <section class="break" aria-labelledby="k3-management">
        @if($preview && $draft)
            <p class="draft" role="note">{{ __('Utkast: det finns frågor kvar att besvara innan årsredovisningen är klar.') }}</p>
        @endif
        <p class="muted">{{ __('Styrelsen avger följande årsredovisning för räkenskapsåret :period.', ['period' => $period]) }}</p>
        <p class="muted">{{ __('Om inte annat särskilt anges redovisas alla belopp i hela kronor.') }}</p>

        <h2 id="k3-management">{{ __('Förvaltningsberättelse') }}</h2>
        @foreach($management as $part)
            <h3>{{ $part['heading'] }}</h3>
            @foreach($part['paragraphs'] as $paragraph)
                <p>{!! nl2br(e($paragraph)) !!}</p>
            @endforeach
        @endforeach

        <h3>{{ __('Flerårsöversikt') }}</h3>
        <table>
            <thead><tr><th scope="col"><span class="muted">{{ __('Belopp i kr') }}</span></th>@foreach($multiYear as $row)<th scope="col" class="num">{{ $row['label'] }}</th>@endforeach</tr></thead>
            <tbody>
                <tr><th scope="row">{{ __('Nettoomsättning') }}</th>@foreach($multiYear as $row)<td class="num">{{ $kr($row['net_sales']) }}</td>@endforeach</tr>
                <tr><th scope="row">{{ __('Resultat efter finansiella poster') }}</th>@foreach($multiYear as $row)<td class="num">{{ $kr($row['after_financial']) }}</td>@endforeach</tr>
                <tr><th scope="row">{{ __('Soliditet (%)') }}</th>@foreach($multiYear as $row)<td class="num">{{ $row['solidity'] === null ? '–' : number_format($row['solidity'], 1, ',', '') }}</td>@endforeach</tr>
                <tr><th scope="row">{{ __('Regelverk') }}</th>@foreach($multiYear as $row)<td class="num">{{ $row['framework'] ?? '–' }}</td>@endforeach</tr>
            </tbody>
        </table>
        @foreach($multiYearParagraphs as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach

        <h3>{{ __('Resultatdisposition') }}</h3>
        <p>{{ __('Styrelsen föreslår att till förfogande stående medel, :amount kr, disponeras enligt följande:', ['amount' => $kr($disposition['free'])]) }}</p>
        <table>
            <thead><tr><th scope="col">{{ __('Förslag') }}</th><th scope="col" class="num">{{ __('Kr') }}</th></tr></thead>
            <tbody>
                @if($disposition['dividend'] > 0)
                    <tr><th scope="row">{{ __('Utdelas till aktieägarna') }}</th><td class="num">{{ $kr($disposition['dividend']) }}</td></tr>
                @endif
                <tr><th scope="row">{{ __('Balanseras i ny räkning') }}</th><td class="num">{{ $kr($disposition['carried']) }}</td></tr>
                <tr class="sum"><th scope="row">{{ __('Summa') }}</th><td class="num">{{ $kr($disposition['free']) }}</td></tr>
            </tbody>
        </table>
        <p>{{ __('Företagets resultat och ställning i övrigt framgår av efterföljande resultat- och balansräkning, rapport över förändringar i eget kapital och noter.') }}</p>
    </section>

    <section class="break" aria-labelledby="k3-income">
        <h2 id="k3-income">{{ __('Resultaträkning') }}</h2>
        <table>
            <thead><tr><th scope="col"><span class="muted">{{ __('Belopp i kr') }}</span></th><th scope="col" class="note">{{ __('Not') }}</th><th scope="col" class="num">{{ $period }}</th>@if($hasPreviousIncome)<th scope="col" class="num">{{ $previousLabel }}</th>@endif</tr></thead>
            <tbody>
                @foreach($income as $line)
                    <tr @class(['sum' => $line['subtotal']])>
                        <th scope="row">{{ $line['label'] }}</th>
                        <td class="note">{{ $noteNumbers[$line['key']] ?? '' }}</td>
                        <td class="num">{{ $kr($line['amount']) }}</td>
                        @if($hasPreviousIncome)<td class="num">{{ $kr($line['previous']) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="break" aria-labelledby="k3-balance">
        <h2 id="k3-balance">{{ __('Balansräkning') }}</h2>
        <table>
            <thead><tr><th scope="col"><span class="muted">{{ __('Belopp i kr') }}</span></th><th scope="col" class="note">{{ __('Not') }}</th><th scope="col" class="num">{{ $closing }}</th><th scope="col" class="num">{{ $opening }}</th></tr></thead>
            <tbody>
                <tr class="head"><th scope="rowgroup" colspan="4">{{ __('Tillgångar') }}</th></tr>
                @foreach(['subscribed'] as $key)
                    @if($sections->has($key))
                        @include('annual-report.partials.k3-balance-section', ['section' => $sections[$key]])
                    @endif
                @endforeach
                @foreach($assetGroups as $group)
                    @if(collect(\App\Domain\AnnualReport\K3Schedule::GROUPS[$group]['sections'])->contains(fn (string $key): bool => $sections->has($key)))
                        <tr class="head"><th scope="rowgroup" colspan="4">{{ __($groups[$group]['label']) }}</th></tr>
                        @foreach(\App\Domain\AnnualReport\K3Schedule::GROUPS[$group]['sections'] as $key)
                            @if($sections->has($key))
                                @include('annual-report.partials.k3-balance-section', ['section' => $sections[$key]])
                            @endif
                        @endforeach
                        <tr class="sum"><th scope="row">{{ __('Summa :group', ['group' => mb_strtolower(__($groups[$group]['label']))]) }}</th><td></td><td class="num">{{ $kr($groups[$group]['amount']) }}</td><td class="num">{{ $kr($groups[$group]['previous']) }}</td></tr>
                    @endif
                @endforeach
                <tr class="total"><th scope="row">{{ __('Summa tillgångar') }}</th><td></td><td class="num">{{ $kr($balance['assets']) }}</td><td class="num">{{ $kr($balance['assets_previous']) }}</td></tr>

                <tr class="head"><th scope="rowgroup" colspan="4">{{ __('Eget kapital och skulder') }}</th></tr>
                @if($sections->has('restricted') || $sections->has('free'))
                    <tr class="head"><th scope="rowgroup" colspan="4">{{ __($groups['equity']['label']) }}</th></tr>
                    @foreach(['restricted', 'free'] as $key)
                        @if($sections->has($key))
                            @include('annual-report.partials.k3-balance-section', ['section' => $sections[$key]])
                        @endif
                    @endforeach
                    <tr class="sum"><th scope="row">{{ __('Summa eget kapital') }}</th><td></td><td class="num">{{ $kr($groups['equity']['amount']) }}</td><td class="num">{{ $kr($groups['equity']['previous']) }}</td></tr>
                @endif
                @foreach(['untaxed', 'provisions', 'long_term', 'short_term'] as $key)
                    @if($sections->has($key))
                        @include('annual-report.partials.k3-balance-section', ['section' => $sections[$key]])
                    @endif
                @endforeach
                <tr class="total"><th scope="row">{{ __('Summa eget kapital och skulder') }}</th><td></td><td class="num">{{ $kr($balance['equity_liabilities']) }}</td><td class="num">{{ $kr($balance['equity_liabilities_previous']) }}</td></tr>
            </tbody>
        </table>
    </section>

    <section class="break" aria-labelledby="k3-equity">
        <h2 id="k3-equity">{{ __('Rapport över förändringar i eget kapital') }}</h2>
        <table>
            <thead><tr><th scope="col"><span class="muted">{{ __('Belopp i kr') }}</span></th>@foreach($equityColumns as $column)<th scope="col" class="num">{{ $column['label'] }}</th>@endforeach<th scope="col" class="num">{{ __('Totalt eget kapital') }}</th></tr></thead>
            <tbody>
                @foreach($equityRows as $row)
                    <tr @class(['sum' => in_array($row['key'], ['opening', 'closing'], true)])>
                        <th scope="row">{{ $row['label'] }}</th>
                        @foreach($equityColumns as $column)<td class="num">{{ $row['amounts'][$column['key']] === 0 && ! in_array($row['key'], ['opening', 'closing'], true) && ! ($row['key'] === 'year_result' && $column['key'] === 'year_result') ? '' : $kr($row['amounts'][$column['key']]) }}</td>@endforeach
                        <td class="num">{{ $kr($row['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if($cashFlow !== null)
        <section class="break" aria-labelledby="k3-cash-flow">
            <h2 id="k3-cash-flow">{{ __('Kassaflödesanalys') }}</h2>
            @php($cashFlowPrevious = collect($cashFlow)->contains(fn (array $row): bool => $row['previous'] !== null))
            <table>
                <thead><tr><th scope="col"><span class="muted">{{ __('Belopp i kr') }}</span></th><th scope="col" class="num">{{ $period }}</th>@if($cashFlowPrevious)<th scope="col" class="num">{{ $previousLabel }}</th>@endif</tr></thead>
                <tbody>
                    @php($current = null)
                    @foreach($cashFlow as $row)
                        @if($row['section'] !== $current && isset($cashFlowSections[$row['section']]))
                            @php($current = $row['section'])
                            <tr class="head"><th scope="rowgroup" colspan="{{ $cashFlowPrevious ? 3 : 2 }}">{{ $cashFlowSections[$row['section']] }}</th></tr>
                        @endif
                        <tr @class(['sum' => $row['subtotal']])>
                            <th scope="row">{{ $row['label'] }}</th>
                            <td class="num">{{ $kr($row['amount']) }}</td>
                            @if($cashFlowPrevious)<td class="num">{{ $kr($row['previous']) }}</td>@endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <section class="break" aria-labelledby="k3-notes">
        <h2 id="k3-notes">{{ __('Noter') }}</h2>
        @if($principles !== [])
            <h3>{{ __('Not 1 Redovisnings- och värderingsprinciper') }}</h3>
            @foreach($principles as $note)
                <h4>{{ $note->heading }}</h4>
                @include('annual-report.partials.k3-note-text', ['note' => $note])
            @endforeach
        @endif

        @foreach($otherNotes as $note)
            <h3>{{ __('Not :number :label', ['number' => $note->number, 'label' => $note->heading]) }}</h3>
            @if(str_starts_with($note->key, 'fixed_assets.') && isset($fixedAssets[$note->data['line']]))
                @php($asset = $fixedAssets[$note->data['line']])
                <table>
                    <thead><tr><th scope="col"><span class="muted">{{ __('Belopp i kr') }}</span></th><th scope="col" class="num">{{ $closing }}</th></tr></thead>
                    <tbody>
                        <tr><th scope="row">{{ __('Ingående anskaffningsvärden') }}</th><td class="num">{{ $kr($asset['acquisition']['opening']) }}</td></tr>
                        <tr><th scope="row">{{ __('Inköp') }}</th><td class="num">{{ $kr($asset['acquisition']['additions']) }}</td></tr>
                        <tr><th scope="row">{{ __('Försäljningar och utrangeringar') }}</th><td class="num">{{ $kr(-$asset['acquisition']['disposals']) }}</td></tr>
                        <tr class="sum"><th scope="row">{{ __('Utgående ackumulerade anskaffningsvärden') }}</th><td class="num">{{ $kr($asset['acquisition']['closing']) }}</td></tr>
                        <tr><th scope="row">{{ __('Ingående avskrivningar') }}</th><td class="num">{{ $kr(-$asset['depreciation']['opening']) }}</td></tr>
                        <tr><th scope="row">{{ __('Försäljningar och utrangeringar') }}</th><td class="num">{{ $kr($asset['depreciation']['disposals']) }}</td></tr>
                        <tr><th scope="row">{{ __('Årets avskrivningar') }}</th><td class="num">{{ $kr(-$asset['depreciation']['charge']) }}</td></tr>
                        <tr class="sum"><th scope="row">{{ __('Utgående ackumulerade avskrivningar') }}</th><td class="num">{{ $kr(-$asset['depreciation']['closing']) }}</td></tr>
                        <tr class="total"><th scope="row">{{ __('Utgående redovisat värde') }}</th><td class="num">{{ $kr($asset['book_value']) }}</td></tr>
                    </tbody>
                </table>
            @endif
            @include('annual-report.partials.k3-note-text', ['note' => $note])
        @endforeach

        <h2 style="margin-top: 26pt;">{{ __('Underskrifter') }}</h2>
        <p>{{ $report->signing_place ?: '……………………' }}, {{ $report->signing_date?->toDateString() ?? '……………………' }}</p>
        <table class="signatures" role="presentation">
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
    </section>

    <section class="break" aria-labelledby="k3-certificate">
        <h2 id="k3-certificate">{{ __('Fastställelseintyg') }}</h2>
        <p>{{ __('Undertecknad :role intygar att resultaträkningen och balansräkningen i denna årsredovisning för :company har fastställts av :meeting den :date.', ['role' => mb_strtolower($certificate['role'] ?: __('styrelseledamot')), 'company' => $name, 'meeting' => mb_strtolower($certificate['meeting']), 'date' => $certificate['date'] ?? '……………………']) }}</p>
        <p>{{ $certificate['decision'] ?: __(':meeting beslutade om vinsten eller förlusten enligt följande:', ['meeting' => $certificate['meeting']]).' ……………………………………………………………………' }}</p>
        <p>{{ __('Jag intygar att innehållet i denna kopia överensstämmer med originalet.') }}</p>
        <p class="muted">{{ __('Årsredovisningslagen 8 kap. 3 §') }}</p>
        <table class="signatures" role="presentation">
            <tbody>
                <tr><td><span class="line">{{ $certificate['name'] ?? '' }}</span><span class="muted">{{ $certificate['role'] ?: __('Styrelseledamot eller verkställande direktör') }}</span></td><td></td></tr>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
