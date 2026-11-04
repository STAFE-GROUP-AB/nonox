@php
    $zone = config('app.timezone');
    $time = fn ($value): string => $value === null ? '' : $value->setTimezone($zone)->format('Y-m-d H:i:s T');
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Signeringsbevis') }}</title>
<style>
    @page { margin: 18mm 16mm 22mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2933; }
    h1 { font-size: 18pt; margin: 0 0 2mm; letter-spacing: -0.3pt; }
    h2 { font-size: 10.5pt; margin: 7mm 0 2mm; }
    .muted { color: #616e7c; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .meta td { padding: 1.2mm 2mm 1.2mm 0; border-bottom: 1px solid #e4e7eb; vertical-align: top; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; word-wrap: break-word; }
    .signature { border: 1px solid #cbd2d9; border-radius: 2mm; padding: 4mm; margin-top: 4mm; }
    .signature img { max-height: 30mm; max-width: 120mm; }
    .typed { font-family: 'DejaVu Serif', serif; font-style: italic; font-size: 20pt; }
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
                <h1>{{ __('Signeringsbevis') }}</h1>
                <span class="muted">{{ $title }}</span>
            </td>
        </tr>
    </table>

    <h2>{{ __('Dokumentet som signerades') }}</h2>
    <table class="meta">
        <tr><td class="muted" style="width: 38%">{{ __('Dokument') }}</td><td>{{ $title }} ({{ $request->document_name }})</td></tr>
        <tr><td class="muted">{{ __('Kontrollsumma (SHA-256)') }}</td><td class="mono">{{ $request->document_sha256 }}</td></tr>
        <tr><td class="muted">{{ __('Skickades till') }}</td><td>{{ $request->email }}</td></tr>
        <tr><td class="muted">{{ __('Avsändare') }}</td><td>{{ $company->legal_name ?: $company->name }}@if($company->org_number) ({{ $company->org_number }})@endif</td></tr>
    </table>

    <h2>{{ __('Underskrift') }}</h2>
    <table class="meta">
        <tr><td class="muted" style="width: 38%">{{ __('Namn') }}</td><td>{{ $request->signer_name }}</td></tr>
        @if($request->signer_title)
            <tr><td class="muted">{{ __('Titel') }}</td><td>{{ $request->signer_title }}</td></tr>
        @endif
        <tr><td class="muted">{{ __('Signerad') }}</td><td>{{ $time($request->signed_at) }}</td></tr>
        <tr><td class="muted">{{ __('Sätt') }}</td><td>{{ $request->signature_method === App\Modules\Crm\Models\SigningRequest::TYPED ? __('Skrivet namn som underskrift') : __('Ritad namnteckning') }}, {{ __('med bekräftelsen ”:text”', ['text' => $request->acceptance_text ?? App\Modules\Crm\Support\SigningPdfs::acceptance($request)]) }}</td></tr>
        <tr><td class="muted">{{ __('IP-adress') }}</td><td>{{ $request->signer_ip }}</td></tr>
        <tr><td class="muted">{{ __('Webbläsare') }}</td><td class="mono">{{ $request->signer_user_agent }}</td></tr>
    </table>

    <div class="signature">
        @if($request->signature_method === App\Modules\Crm\Models\SigningRequest::DRAWN && $request->signature_image)
            <img src="data:image/png;base64,{{ $request->signature_image }}" alt="{{ __('Namnteckning') }}">
        @else
            <span class="typed">{{ $request->signer_name }}</span>
        @endif
    </div>

    <h2>{{ __('Händelser') }}</h2>
    <table class="meta">
        @foreach($request->events as $event)
            <tr><td class="muted" style="width: 38%">{{ $time($event->created_at) }}</td><td>{{ $event->event->label() }}</td></tr>
        @endforeach
    </table>

    <div class="box">
        <span class="muted">{{ __('En enkel elektronisk underskrift. Den signerade PDF:en är oförändrad: räkna fram dess SHA-256 och jämför med kontrollsumman ovan för att se att det är samma dokument. Signeringen gjordes i NONOX via en personlig länk som skickades till e-postadressen ovan.') }}</span>
    </div>
</body>
</html>
