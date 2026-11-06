{{-- The K2 annual report as Inline XBRL (ADR-058 E.2). XHTML: every element closed, no named entities, no external resources. --}}
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:ix="http://www.xbrl.org/2013/inlineXBRL" xmlns:ixt="{{ \App\Support\Ixbrl\InlineXbrlWriter::TRANSFORMATIONS }}" xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:link="http://www.xbrl.org/2003/linkbase" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:iso4217="http://www.xbrl.org/2003/iso4217" @foreach(\App\Domain\AnnualReport\K2Taxonomy::NAMESPACES as $prefix => $namespace)xmlns:{{ $prefix }}="{{ $namespace }}" @endforeach xml:lang="sv">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ __('Årsredovisning') }} {{ $company->legal_name ?: $company->name }} {{ $fiscalYear->label() }}</title>
<style type="text/css">
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5pt; color: #17171a; line-height: 1.45; max-width: 190mm; margin: 0 auto; padding: 12mm 10mm; }
    h1 { font-size: 22pt; margin: 0 0 4pt; }
    h2 { font-size: 13pt; margin: 18pt 0 6pt; }
    h3 { font-size: 10.5pt; margin: 12pt 0 4pt; }
    p { margin: 0 0 6pt; }
    .muted { color: #6b6b70; }
    .cover { text-align: center; padding: 40mm 0; }
    .break { page-break-before: always; border-top: 0.6pt solid #d6d3d1; margin-top: 18pt; }
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
<div style="display: none">{!! $header !!}</div>
{!! $body !!}
</body>
</html>
