@php
    $zone = config('app.timezone');
    $time = fn ($value): string => $value === null ? '' : $value->setTimezone($zone)->format('Y-m-d H:i:s T');
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Sammanställning av signeringen') }}</title>
<style>
    @page { margin: 18mm 16mm 22mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2933; }
    h1 { font-size: 18pt; margin: 0 0 2mm; letter-spacing: -0.3pt; }
    h2 { font-size: 10.5pt; margin: 7mm 0 2mm; }
    .muted { color: #616e7c; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .meta td, .meta th { padding: 1.2mm 2mm 1.2mm 0; border-bottom: 1px solid #e4e7eb; vertical-align: top; text-align: left; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; word-wrap: break-word; }
    .box { border: 1px solid #cbd2d9; border-radius: 2mm; padding: 4mm; margin-top: 8mm; }
    @include('invoicing.partials.brand-style')
</style>
</head>
<body>
    <div class="brand-bar"></div>
    <table class="head">
        <tr>
            <td style="width: 55%">
                @include('invoicing.partials.brand-sender')
            </td>
            <td>
                <h1>{{ __('Sammanställning av signeringen') }}</h1>
                <span class="muted">{{ $title }}</span>
            </td>
        </tr>
    </table>

    <h2>{{ __('Dokumentet som signerades') }}</h2>
    <table class="meta">
        <tr><td class="muted" style="width: 38%">{{ __('Dokument') }}</td><td>{{ $title }} ({{ $envelope->document_name }})</td></tr>
        <tr><td class="muted">{{ __('Kontrollsumma (SHA-256)') }}</td><td class="mono">{{ $envelope->document_sha256 }}</td></tr>
        <tr><td class="muted">{{ __('Avsändare') }}</td><td>{{ $company->legal_name ?: $company->name }}@if($company->org_number) ({{ $company->org_number }})@endif</td></tr>
        <tr><td class="muted">{{ __('Signerat av alla') }}</td><td>{{ $time($envelope->completed_at) }}</td></tr>
    </table>

    <h2>{{ __('Undertecknare') }}</h2>
    <table class="meta">
        <tr><th class="muted">{{ __('Steg') }}</th><th class="muted">{{ __('Namn') }}</th><th class="muted">{{ __('Signerad') }}</th><th class="muted">{{ __('Sätt') }}</th></tr>
        @foreach($signers as $signer)
            <tr>
                <td>{{ $signer->step }}</td>
                <td>{{ $signer->signer_name }}@if($signer->signer_title), {{ $signer->signer_title }}@endif</td>
                <td>{{ $time($signer->signed_at) }}</td>
                <td>{{ $signer->signature_method === App\Models\SigningRequest::TYPED ? __('Skrivet namn som underskrift') : __('Ritad namnteckning') }}@if($signer->verification->required()) · {{ $signer->verification->evidence() }}@endif</td>
            </tr>
        @endforeach
    </table>

    <div class="box">
        <span class="muted">{{ __('En sammanställning för den som får en kopia. Varje underskrift är en enkel elektronisk underskrift, och PDF:en är oförändrad: räkna fram dess SHA-256 och jämför med kontrollsumman ovan för att se att det är samma dokument. Det fullständiga signeringsbeviset för varje underskrift finns hos avsändaren.') }}</span>
    </div>
</body>
</html>
