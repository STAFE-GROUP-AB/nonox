{{-- One owner's K10 worksheet as a page and a PDF (App\Actions\IncomeTax\K10Worksheets, ADR-062). --}}
@php
    /** @var \App\Domain\IncomeTax\K10Sheet $sheet */
    $k10 = $sheet->k10();
    $owner = $sheet->owner;
    $company = $sheet->company;
    $labels = \App\Domain\IncomeTax\K10Codes::LABELS;
    $codes = \App\Domain\IncomeTax\K10Codes::FIELDS;
    $kr = fn (?int $kronor): string => $kronor === null ? '–' : number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    $year = $sheet->incomeYear;
    $previous = $year - 1;
    $origin = fn (string $key): string => match ($sheet->origin($key)) {
        'payroll' => __('från lönekörningarna'),
        'ledger' => __('från bokföringen'),
        'entered', 'entered_total' => __('ifyllt'),
        default => __('inte angivet'),
    };
    $sections = [
        'A' => [__('A. Gränsbelopp'), ['1.1', '1.2', '1.3', '1.4', '1.6']],
        'B' => [__('B. Utdelning'), ['2.1', '2.2', '2.3', '2.4', '2.6', '2.7', '2.8', '2.9', '2.10']],
        'D' => [__('D. Lönebaserat utrymme'), ['4.1', '4.2', '4.3', '4.4', '4.5', '4.6', '4.7', '4.8', '4.9', '4.10', '4.11', '4.12', '4.13']],
    ];
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('K10-underlag') }} {{ $owner->name }} {{ $year }}</title>
<style>
    @page { margin: 20mm 18mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #17171a; line-height: 1.45; max-width: 190mm; margin: 0 auto; }
    h1 { font-size: 18pt; margin: 0 0 4pt; }
    h2 { font-size: 12pt; margin: 16pt 0 6pt; }
    p { margin: 0 0 6pt; }
    .muted { color: #6b6b70; }
    .beta { display: inline-block; border: 0.8pt solid #17171a; padding: 1pt 5pt; font-size: 8pt; font-weight: bold; }
    .notice { border: 0.8pt solid #b45309; padding: 5pt 7pt; margin: 6pt 0; }
    table { width: 100%; border-collapse: collapse; margin: 4pt 0 10pt; }
    th { text-align: left; font-weight: normal; color: #6b6b70; border-bottom: 0.8pt solid #17171a; padding: 3pt 4pt; font-size: 8.5pt; }
    td { padding: 2.5pt 4pt; vertical-align: top; border-bottom: 0.3pt solid #d6d3d1; }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .point { width: 32pt; }
    .code { width: 40pt; color: #6b6b70; }
    tr.sum td { font-weight: bold; }
</style>
</head>
<body>
    <p><span class="beta">{{ __('Beta') }}</span> <span class="muted">{{ __('Förslag och arbetsunderlag, inte en deklaration') }}</span></p>
    <h1>{{ __('K10-underlag inkomstår :year', ['year' => $year]) }}</h1>
    <p>{{ $owner->name }}@if(filled($owner->identity)), {{ $owner->maskedIdentity() }}@endif</p>
    <p>{{ $company->legal_name ?: $company->name }}, {{ $company->org_number }}</p>
    <p class="muted">{{ __('Kvalificerade aktier i fåmansföretag, blankett K10 (SKV 2110) för beskattningsperiod :period. Framtaget :date i NONOX.', ['period' => $sheet->period(), 'date' => now()->toDateString()]) }}</p>

    <p class="notice">{{ __('Underlaget följer reglerna i inkomstskattelagen 57 kap. som gäller från inkomstår 2026 (SFS 2025:1361). Det är inte granskat av en jurist. Ägaren lämnar K10 själv med sin Inkomstdeklaration 1 och ansvarar för uppgifterna. Ingenting är bokfört eller lämnat till Skatteverket.') }}</p>

    @foreach($sheet->blockers() as $blocker)
        <p class="notice">{{ $blocker }}</p>
    @endforeach

    @if($k10 !== null)
        @php($input = $sheet->input())
        <h2>{{ __('Uppgifter') }}</h2>
        <table>
            <tbody>
                <tr><td>{{ __('Antal ägda aktier vid årets ingång (4531)') }}</td><td class="num">{{ number_format($input->sharesOwned, 0, ',', "\u{a0}") }}</td></tr>
                <tr><td>{{ __('Totala antalet aktier i företaget vid årets ingång (4532)') }}</td><td class="num">{{ number_format($input->sharesTotal, 0, ',', "\u{a0}") }}</td></tr>
                @if($k10->isSpouseCalculation())
                    <tr><td>{{ __('Make eller makas aktier (gemensam beräkning)') }}</td><td class="num">{{ number_format($input->spouseShares, 0, ',', "\u{a0}") }}</td></tr>
                @endif
                <tr><td>{{ __('Löner i företaget under :year, :origin', ['year' => $previous, 'origin' => $origin('company_pay')]) }}</td><td class="num">{{ $kr($input->companyPayKronor) }}</td></tr>
                <tr><td>{{ __('Högsta kontanta ersättning till dig eller en närstående under :year', ['year' => $previous]) }}</td><td class="num">{{ $kr($input->capBasisKronor) }}</td></tr>
                <tr><td>{{ __('Omkostnadsbelopp') }}</td><td class="num">{{ $kr($input->costBasisKronor) }}</td></tr>
                <tr><td>{{ $owner->saved_space_carried ? __('Sparat utdelningsutrymme, förs över från förra årets underlag') : __('Sparat utdelningsutrymme') }}</td><td class="num">{{ $kr($input->savedSpaceKronor) }}</td></tr>
                <tr><td>{{ __('Utdelning under :year, :origin', ['year' => $year, 'origin' => $origin('dividend')]) }}</td><td class="num">{{ $kr($input->dividendKronor) }}</td></tr>
            </tbody>
        </table>

        @foreach($sections as $key => [$title, $points])
            @if($key === 'B' && ! $k10->hasDividend())
                <h2>{{ $title }}</h2>
                <p>{{ __('Ingen utdelning under :year. Hela gränsbeloppet, :amount, förs vidare som sparat utdelningsutrymme till nästa år.', ['year' => $year, 'amount' => $kr($k10->limitKronor())]) }}</p>
                @continue
            @endif
            <h2>{{ $title }}</h2>
            <table>
                <thead><tr><th class="point">{{ __('Punkt') }}</th><th>{{ __('Uppgift') }}</th><th class="code">{{ __('Fält') }}</th><th class="num">{{ __('Belopp') }}</th></tr></thead>
                <tbody>
                    @foreach($points as $point)
                        @continue($k10->point($point) === null)
                        <tr @class(['sum' => in_array($point, ['1.6', '2.6', '2.10', '4.12'], true)])>
                            <td class="point">{{ $point }}</td>
                            <td>{{ __($labels[$point]) }}</td>
                            <td class="code">{{ $codes[$point] }}</td>
                            <td class="num">{{ $kr($k10->point($point)) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach

        @if($sheet->warnings() !== [])
            <h2>{{ __('Att kontrollera') }}</h2>
            @foreach($sheet->warnings() as $warning)
                <p>{{ $warning }}</p>
            @endforeach
        @endif

        <h2>{{ __('Så är det räknat') }}</h2>
        <p>{{ __('Grundbelopp: fyra inkomstbasbelopp för :previous (4 × :ibb) fördelat på företagets aktier, högst ett grundbelopp för alla dina fåmansföretag (IL 57 kap. 11 och 11 a §§).', ['previous' => $previous, 'ibb' => $kr(intdiv($k10->parameters->int('income_base_amount_previous_year_ore'), 100))]) }}</p>
        <p>{{ __('Lönebaserat utrymme: 50 % av din andel av löneunderlaget minus åtta inkomstbasbelopp, högst 50 gånger den högsta kontanta ersättningen till dig eller en närstående under :previous (16–18 §§).', ['previous' => $previous]) }}</p>
        <p>{{ __('Ränta: den del av omkostnadsbeloppet som överstiger 100 000 kr gånger statslåneräntan den 30 november :previous plus nio procentenheter, :rate % (11 §).', ['previous' => $previous, 'rate' => number_format($k10->parameters->costBasisInterestBp() / 100, 2, ',', '')]) }}</p>
        <p>{{ __('Utdelning upp till gränsbeloppet tas upp till två tredjedelar i kapital, utdelning över gränsbeloppet i tjänst upp till 90 inkomstbasbelopp (20 och 20 a §§). Sparat utdelningsutrymme räknas inte upp från inkomstår 2026 (13 §).') }}</p>
        <p class="muted">{{ __('Belopp som bygger upp gränsbeloppet är avrundade nedåt till hela kronor.') }}</p>
    @endif
</body>
</html>
