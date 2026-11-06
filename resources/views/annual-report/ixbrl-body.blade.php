@use('App\Domain\AnnualReport\K2Taxonomy', 'Tx')
@php
    /** @var \App\Support\Ixbrl\InlineXbrlWriter $x */
    $name = $company->legal_name ?: $company->name;
    $year = $fiscalYear->label();
    $first = $fiscalYear->start_date->toDateString();
    $last = $fiscalYear->end_date->toDateString();
    $signatories = collect($report->signatories)->filter(fn (array $person): bool => trim($person['name'] ?? '') !== '');
    // A translated sentence with tagged amounts in its placeholders; the rest of the sentence is escaped.
    $sentence = fn (string $text, array $markup): string => strtr(e($text), $markup);
    $clean = fn (?string $value): string => \App\Support\Ixbrl\InlineXbrlWriter::xmlText((string) $value);
    $text = fn (?string $value): string => nl2br(e($clean($value)));
@endphp
<div class="cover">
    <h1>{{ __('Årsredovisning') }}</h1>
    <p style="font-size: 13pt;">{{ __('för') }}</p>
    <p style="font-size: 15pt; font-weight: bold;">{!! $x->text(Tx::FACTS['company_name'], __('Företagets namn'), e($clean($name)), $current) !!}</p>
    <p>{!! $x->text(Tx::FACTS['org_number'], __('Organisationsnummer'), e($clean($company->org_number)), $current) !!}</p>
    <p style="margin-top: 14pt;">{!! $sentence(__('Räkenskapsåret :period'), [':period' => $x->date(Tx::FACTS['first_day'], __('Räkenskapsårets första dag'), $first, $current).' – '.$x->date(Tx::FACTS['last_day'], __('Räkenskapsårets sista dag'), $last, $current)]) !!}</p>
</div>

<div class="break">
    <p class="muted">{{ __('Styrelsen avger följande årsredovisning för räkenskapsåret :period.', ['period' => $first.' – '.$last]) }}</p>
    <p class="muted">{{ __('Om inte annat särskilt anges redovisas alla belopp i hela kronor.') }}</p>

    <h2>{{ __('Förvaltningsberättelse') }}</h2>
    <h3>{{ __('Allmänt om verksamheten') }}</h3>
    <p>{!! $x->text(Tx::FACTS['business_description'], __('Allmänt om verksamheten'), $text($report->business_description), $current) !!}</p>

    @if(filled($report->significant_events))
        <h3>{{ __('Väsentliga händelser under räkenskapsåret') }}</h3>
        <p>{!! $x->text(Tx::FACTS['significant_events'], __('Väsentliga händelser under räkenskapsåret'), $text($report->significant_events), $current) !!}</p>
    @endif

    <h3>{{ __('Flerårsöversikt') }}</h3>
    <table>
        <thead><tr><th></th>@foreach($multiYear as $row)<th class="num">{{ $row['label'] }}</th>@endforeach</tr></thead>
        <tbody>
            <tr><td>{{ __('Nettoomsättning') }}</td>@foreach($multiYear as $row)<td class="num">{!! $x->money(Tx::MULTI_YEAR['net_sales'], __('Flerårsöversikt').': '.__('Nettoomsättning'), $row['net_sales'], 'credit', $years[$row['label']]) !!}</td>@endforeach</tr>
            <tr><td>{{ __('Resultat efter finansiella poster') }}</td>@foreach($multiYear as $row)<td class="num">{!! $x->money(Tx::MULTI_YEAR['after_financial'], __('Flerårsöversikt').': '.__('Resultat efter finansiella poster'), $row['after_financial'], 'credit', $years[$row['label']]) !!}</td>@endforeach</tr>
            <tr><td>{{ __('Soliditet (%)') }}</td>@foreach($multiYear as $row)<td class="num">{!! $x->percent(Tx::MULTI_YEAR['solidity'], __('Flerårsöversikt').': '.__('Soliditet (%)'), $row['solidity'], $years[$row['label']]) !!}</td>@endforeach</tr>
        </tbody>
    </table>
    @foreach($netSalesVariations as $variation)
        <p>{!! $sentence(__('Nettoomsättningen har förändrats med mer än 30 procent, från :from kr :fromYear till :to kr :toYear.'), [
            ':fromYear' => e($variation['from']),
            ':toYear' => e($variation['to']),
            ':from' => $x->money(Tx::MULTI_YEAR['net_sales'], __('Flerårsöversikt').': '.__('Nettoomsättning'), $variation['from_amount'], 'credit', $years[$variation['from']]),
            ':to' => $x->money(Tx::MULTI_YEAR['net_sales'], __('Flerårsöversikt').': '.__('Nettoomsättning'), $variation['to_amount'], 'credit', $years[$variation['to']]),
        ]) !!}</p>
    @endforeach
    @if(filled($report->net_sales_comment))
        <p>{!! $x->text(Tx::FACTS['net_sales_comment'], __('Kommentar till nettoomsättningen'), $text($report->net_sales_comment), $current) !!}</p>
    @endif

    <h3>{{ __('Förändringar i eget kapital') }}</h3>
    <table>
        <thead><tr><th></th>@foreach($equityColumns as $column)<th class="num">{{ $column['label'] }}</th>@endforeach<th class="num">{{ __('Totalt') }}</th></tr></thead>
        <tbody>
            @foreach($equityChanges as $row)
                @php($rowPeriod = $row['key'] === 'opening' ? $previous : $current)
                @php($concepts = Tx::EQUITY[$row['key']] ?? [])
                {{-- No empty class attribute: the XHTML schema rejects class="" (Arelle). --}}
                <tr{!! $row['key'] === 'closing' ? ' class="sum"' : '' !!}>
                    <td>{{ $row['label'] }}</td>
                    @foreach($equityColumns as $column)<td class="num">@if(! ($row['amounts'][$column['key']] === 0 && ! in_array($row['key'], ['opening', 'closing'], true))){!! $x->money($concepts[$column['key']] ?? null, __('Förändringar i eget kapital').': '.$row['label'].', '.$column['label'], $row['amounts'][$column['key']], 'credit', $rowPeriod) !!}@endif</td>@endforeach
                    <td class="num">{!! $x->money($concepts['total'] ?? null, __('Förändringar i eget kapital').': '.$row['label'].', '.__('Totalt'), $row['total'], 'credit', $rowPeriod) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>{{ __('Resultatdisposition') }}</h3>
    <p>{!! $sentence(__('Styrelsen föreslår att till förfogande stående medel, :amount kr, disponeras enligt följande:'), [':amount' => $x->money(Tx::DISPOSITION['free'], __('Resultatdisposition'), $disposition['free'], 'credit', $current)]) !!}</p>
    <table>
        <tbody>
            @if($disposition['dividend'] > 0)
                <tr><td>{{ __('Utdelas till aktieägarna') }}</td><td class="num">{!! $x->money(Tx::DISPOSITION['dividend'], __('Resultatdisposition').': '.__('Utdelas till aktieägarna'), $disposition['dividend'], 'credit', $current) !!}</td></tr>
            @endif
            <tr><td>{{ __('Balanseras i ny räkning') }}</td><td class="num">{!! $x->money(Tx::DISPOSITION['carried'], __('Resultatdisposition').': '.__('Balanseras i ny räkning'), $disposition['carried'], 'credit', $current) !!}</td></tr>
            <tr class="sum"><td>{{ __('Summa') }}</td><td class="num">{!! $x->money(Tx::DISPOSITION['total'], __('Resultatdisposition').': '.__('Summa'), $disposition['free'], 'credit', $current) !!}</td></tr>
        </tbody>
    </table>
    <p>{{ __('Företagets resultat och ställning i övrigt framgår av efterföljande resultat- och balansräkning med noter.') }}</p>
</div>

<div class="break">
    <h2>{{ __('Resultaträkning') }}</h2>
    <table>
        <thead><tr><th></th><th class="note">{{ __('Not') }}</th><th class="num">{{ $first.' – '.$last }}</th>@if($hasPreviousIncome)<th class="num">{{ $previousLabel }}</th>@endif</tr></thead>
        <tbody>
            @foreach($income as $line)
                <tr{!! $line['subtotal'] ? ' class="sum"' : '' !!}>
                    <td>{{ $line['label'] }}</td>
                    <td class="note">@if($line['key'] === 'personnel'){{ 2 }}@endif</td>
                    <td class="num">{!! $x->money(Tx::INCOME[$line['key']] ?? null, __('Resultaträkning').': '.$line['label'], $line['amount'], 'credit', $current) !!}</td>
                    @if($hasPreviousIncome)<td class="num">{!! $x->money(Tx::INCOME[$line['key']] ?? null, __('Resultaträkning').': '.$line['label'], (int) $line['previous'], 'credit', $previous) !!}</td>@endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="break">
    <h2>{{ __('Balansräkning') }}</h2>
    @php($fixedNotes = collect($fixedAssets)->pluck('key')->flip()->map(fn (int $index): int => 3 + $index))
    @php($assetsDone = false)
    <table>
        <thead><tr><th></th><th class="note">{{ __('Not') }}</th><th class="num">{{ $last }}</th><th class="num">{{ $fiscalYear->start_date->subDay()->toDateString() }}</th></tr></thead>
        <tbody>
            <tr class="head"><td colspan="4">{{ __('Tillgångar') }}</td></tr>
            @foreach($balance['sections'] as $section)
                @php($orientation = in_array($section['key'], \App\Domain\AnnualReport\K2Schedule::ASSET_SECTIONS, true) ? 'debit' : 'credit')
                @if(! $assetsDone && ! in_array($section['key'], ['fixed', 'current'], true))
                    @php($assetsDone = true)
                    <tr class="total"><td>{{ __('Summa tillgångar') }}</td><td></td><td class="num">{!! $x->money(Tx::TOTALS['assets'], __('Summa tillgångar'), $balance['assets'], 'debit', $current) !!}</td><td class="num">{!! $x->money(Tx::TOTALS['assets'], __('Summa tillgångar'), $balance['assets_previous'], 'debit', $previous) !!}</td></tr>
                    <tr class="head"><td colspan="4">{{ __('Eget kapital och skulder') }}</td></tr>
                @endif
                <tr class="head"><td colspan="4">{{ $section['label'] }}</td></tr>
                @foreach($section['lines'] as $line)
                    <tr>
                        <td>{{ $line['label'] }}</td>
                        <td class="note">{{ $fixedNotes[$line['key']] ?? '' }}</td>
                        <td class="num">{!! $x->money(Tx::BALANCE[$line['key']] ?? null, __('Balansräkning').': '.$line['label'], $line['amount'], $orientation, $current) !!}</td>
                        <td class="num">{!! $x->money(Tx::BALANCE[$line['key']] ?? null, __('Balansräkning').': '.$line['label'], $line['previous'], $orientation, $previous) !!}</td>
                    </tr>
                @endforeach
                @php($sumLabel = __('Summa :section', ['section' => mb_strtolower($section['label'])]))
                <tr class="sum"><td>{{ $sumLabel }}</td><td></td><td class="num">{!! $x->money(Tx::SECTIONS[$section['key']] ?? null, __('Balansräkning').': '.$sumLabel, $section['amount'], $orientation, $current) !!}</td><td class="num">{!! $x->money(Tx::SECTIONS[$section['key']] ?? null, __('Balansräkning').': '.$sumLabel, $section['previous'], $orientation, $previous) !!}</td></tr>
            @endforeach
            <tr class="total"><td>{{ __('Summa eget kapital och skulder') }}</td><td></td><td class="num">{!! $x->money(Tx::TOTALS['equity_liabilities'], __('Summa eget kapital och skulder'), $balance['equity_liabilities'], 'credit', $current) !!}</td><td class="num">{!! $x->money(Tx::TOTALS['equity_liabilities'], __('Summa eget kapital och skulder'), $balance['equity_liabilities_previous'], 'credit', $previous) !!}</td></tr>
        </tbody>
    </table>
</div>

<div class="break">
    <h2>{{ __('Noter') }}</h2>
    <h3>{{ __('Not 1 Redovisningsprinciper') }}</h3>
    @php($principles = '<p>'.e(__('Årsredovisningen har upprättats i enlighet med årsredovisningslagen och Bokföringsnämndens allmänna råd (BFNAR 2016:10) om årsredovisning i mindre företag.')).'</p>'.($depreciation !== [] ? '<p>'.e(__('Materiella anläggningstillgångar skrivs av linjärt över den bedömda nyttjandeperioden:').' '.implode(', ', $depreciation).'.').'</p>' : ''))
    <div>{!! $x->text(Tx::FACTS['principles'], __('Not 1 Redovisningsprinciper'), $principles, $current) !!}</div>

    <h3>{{ __('Not 2 Medelantalet anställda') }}</h3>
    <table>
        <thead><tr><th></th><th class="num">{{ $year }}</th></tr></thead>
        <tbody><tr><td>{{ __('Medelantalet anställda') }}</td><td class="num">{!! $x->count(Tx::FACTS['average_employees'], __('Not 2 Medelantalet anställda'), (int) $report->average_employees, $current) !!}</td></tr></tbody>
    </table>

    @php($noteNumber = 2)
    @foreach($fixedAssets as $note)
        @php($noteNumber++)
        @php($noteLabel = __('Not :number :label', ['number' => $noteNumber, 'label' => $note['label']]))
        @php($c = Tx::FIXED_ASSET_NOTES[$note['key']] ?? [])
        <h3>{{ $noteLabel }}</h3>
        <table>
            <thead><tr><th></th><th class="num">{{ $last }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('Ingående anskaffningsvärden') }}</td><td class="num">{!! $x->money($c['acquisition'] ?? null, $noteLabel, $note['acquisition']['opening'], 'debit', $previous) !!}</td></tr>
                <tr><td>{{ __('Inköp') }}</td><td class="num">{!! $x->money($c['additions'] ?? null, $noteLabel, $note['acquisition']['additions'], 'debit', $current) !!}</td></tr>
                <tr><td>{{ __('Försäljningar och utrangeringar') }}</td><td class="num">{!! $x->money($c['acquisition_disposals'] ?? null, $noteLabel, -$note['acquisition']['disposals'], 'debit', $current) !!}</td></tr>
                <tr class="sum"><td>{{ __('Utgående ackumulerade anskaffningsvärden') }}</td><td class="num">{!! $x->money($c['acquisition'] ?? null, $noteLabel, $note['acquisition']['closing'], 'debit', $current) !!}</td></tr>
                <tr><td>{{ __('Ingående avskrivningar') }}</td><td class="num">{!! $x->money($c['depreciation'] ?? null, $noteLabel, -$note['depreciation']['opening'], 'debit', $previous) !!}</td></tr>
                <tr><td>{{ __('Försäljningar och utrangeringar') }}</td><td class="num">{!! $x->money($c['depreciation_disposals'] ?? null, $noteLabel, $note['depreciation']['disposals'], 'debit', $current) !!}</td></tr>
                <tr><td>{{ __('Årets avskrivningar') }}</td><td class="num">{!! $x->money($c['charge'] ?? null, $noteLabel, -$note['depreciation']['charge'], 'debit', $current) !!}</td></tr>
                <tr class="sum"><td>{{ __('Utgående ackumulerade avskrivningar') }}</td><td class="num">{!! $x->money($c['depreciation'] ?? null, $noteLabel, -$note['depreciation']['closing'], 'debit', $current) !!}</td></tr>
                <tr class="total"><td>{{ __('Utgående redovisat värde') }}</td><td class="num">{!! $x->money($c['book_value'] ?? null, $noteLabel, $note['book_value'], 'debit', $current) !!}</td></tr>
            </tbody>
        </table>
    @endforeach

    @if($pledged !== [])
        @php($noteNumber++)
        @php($noteLabel = __('Not :number :label', ['number' => $noteNumber, 'label' => __('Ställda säkerheter')]))
        <h3>{{ $noteLabel }}</h3>
        <table>
            <thead><tr><th></th><th class="num">{{ $last }}</th></tr></thead>
            <tbody>
                @foreach($pledged as $row)
                    <tr><td>{{ $row['label'] }}</td><td class="num">{!! $x->money(Tx::PLEDGED[$row['kind']] ?? null, $noteLabel.': '.$row['label'], $row['amount'], 'debit', $current) !!}</td></tr>
                @endforeach
                <tr class="sum"><td>{{ __('Summa ställda säkerheter') }}</td><td class="num">{!! $x->money(Tx::PLEDGED['total'], $noteLabel, array_sum(array_column($pledged, 'amount')), 'debit', $current) !!}</td></tr>
            </tbody>
        </table>
    @endif

    @if($report->contingent_liabilities_ore !== null || filled($report->contingent_liabilities_text))
        @php($noteNumber++)
        @php($noteLabel = __('Not :number :label', ['number' => $noteNumber, 'label' => __('Eventualförpliktelser')]))
        <h3>{{ $noteLabel }}</h3>
        <table>
            <thead><tr><th></th><th class="num">{{ $last }}</th></tr></thead>
            <tbody><tr><td>@if(filled($report->contingent_liabilities_text)){!! $x->text(Tx::FACTS['contingent_text'], $noteLabel, e($clean($report->contingent_liabilities_text)), $current) !!}@else{{ __('Eventualförpliktelser') }}@endif</td><td class="num">@if($contingent !== null){!! $x->money(Tx::FACTS['contingent'], $noteLabel, $contingent, 'debit', $current) !!}@endif</td></tr></tbody>
        </table>
    @endif

    @if(filled($report->group_commitments))
        @php($noteNumber++)
        @php($noteLabel = __('Not :number :label', ['number' => $noteNumber, 'label' => __('Åtaganden till förmån för koncern- och intresseföretag')]))
        <h3>{{ $noteLabel }}</h3>
        <p>{!! $x->text(Tx::FACTS['group_commitments'], $noteLabel, $text($report->group_commitments), $current) !!}</p>
    @endif

    @if(filled($report->events_after))
        @php($noteNumber++)
        @php($noteLabel = __('Not :number :label', ['number' => $noteNumber, 'label' => __('Väsentliga händelser efter räkenskapsårets slut')]))
        <h3>{{ $noteLabel }}</h3>
        @php($events = '<p>'.$text($report->events_after).'</p>'.($report->events_effect_unknown ? '<p>'.e(__('Den ekonomiska effekten av händelserna kan inte uppskattas.')).'</p>' : ''))
        <div>{!! $x->text(Tx::FACTS['events_after'], $noteLabel, $events, $current) !!}</div>
    @endif

    <h2 style="margin-top: 26pt;">{{ __('Underskrifter') }}</h2>
    <p>{{ $report->signing_place ?: '……………………' }}, @if($report->signing_date !== null){!! $x->date(Tx::FACTS['signing_date'], __('Datum för underskrift'), $report->signing_date->toDateString(), $current) !!}@else{{ '……………………' }}@endif</p>
    @if($signatories->isNotEmpty())
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
    @endif
</div>

<div class="break">
    <h2>{{ __('Fastställelseintyg') }}</h2>
    @if($certificate['date'] !== null)
        {{-- The schema allows only its own fixed texts for these two facts (se-comp-base 2020-12-01). --}}
        <p>{!! $x->text(Tx::CERTIFICATE['adoption'], __('Fastställelseintyg'), e(Tx::ADOPTION_TEXT), $current) !!} {!! $x->date(Tx::CERTIFICATE['meeting_date'], __('Fastställelsedag'), $certificate['date'], $current) !!}.</p>
        <p>{{ $certificate['decision'] ?: __(':meeting beslutade om vinsten eller förlusten enligt följande:', ['meeting' => $certificate['meeting']]).' ……………………………………………………………………' }}</p>
        <p>{!! $x->text(Tx::CERTIFICATE['original'], __('Fastställelseintyg'), e(Tx::ORIGINAL_TEXT), $current) !!}</p>
    @else
        <p>{{ __('Fastställelsedagen är inte ifylld. Intyget tas med i filen när fastställelsedagen är ifylld under Fastställelseintyg.') }}</p>
    @endif
    <p class="muted">{{ __('Årsredovisningslagen 8 kap. 3 §') }}</p>
    <p class="muted">{{ __('Fastställelseintyget skrivs under elektroniskt hos Bolagsverket. Namn, roll och datum för underskriften fylls i där.') }}</p>
</div>
