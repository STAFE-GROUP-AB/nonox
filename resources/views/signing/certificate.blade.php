@php
    $b = fn (string $key, array $replace = []): string => App\Domain\Signing\SigningPdfs::bilingual($key, $replace);
    $zone = config('app.timezone');
    $time = fn ($value): string => $value === null ? '' : $value->setTimezone($zone)->format('Y-m-d H:i:s T');
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $b('Signeringsbevis') }}</title>
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
                <h1>{{ $b('Signeringsbevis') }}</h1>
                <span class="muted">{{ $title }}</span>
            </td>
        </tr>
    </table>

    <h2>{{ $b('Dokumentet som signerades') }}</h2>
    <table class="meta">
        <tr><td class="muted" style="width: 38%">{{ $b('Dokument') }}</td><td>{{ $title }} ({{ $request->document_name }})</td></tr>
        <tr><td class="muted">{{ $b('Kontrollsumma (SHA-256)') }}</td><td class="mono">{{ $request->document_sha256 }}</td></tr>
        <tr><td class="muted">{{ $request->channel === 'PORTAL' ? $b('Inloggad som') : $b('Skickades till') }}</td><td>{{ $request->email }}</td></tr>
        <tr><td class="muted">{{ $b('Avsändare') }}</td><td>{{ $company->legal_name ?: $company->name }}@if($company->org_number) ({{ $company->org_number }})@endif</td></tr>
    </table>

    @include('signing.verify-box', ['hash' => $request->document_sha256, 'company' => $company, 'b' => $b])

    <h2>{{ $b('Underskrift') }}</h2>
    <table class="meta">
        <tr><td class="muted" style="width: 38%">{{ $b('Namn') }}</td><td>{{ $request->signer_name }}</td></tr>
        @if($request->signer_title)
            <tr><td class="muted">{{ $b('Titel') }}</td><td>{{ $request->signer_title }}</td></tr>
        @endif
        <tr><td class="muted">{{ $b('Signerad') }}</td><td>{{ $time($request->signed_at) }}</td></tr>
        <tr><td class="muted">{{ $b('Sätt') }}</td><td>{{ $request->signature_method === App\Models\SigningRequest::TYPED ? $b('Skrivet namn som underskrift') : $b('Ritad namnteckning') }}, {{ $b('med bekräftelsen') }} ”{{ $request->acceptance_text ?? App\Domain\Signing\SigningPdfs::acceptance($request) }}”</td></tr>
        @if($request->verification->required())
            <tr><td class="muted">{{ $b('Kontroll av undertecknaren') }}</td><td>{{ App\Domain\Signing\SigningPdfs::bilingualOf(fn () => $request->verification->evidence()) }}@if($request->verified_at), {{ $b('godkänd :time', ['time' => $time($request->verified_at)]) }}@endif</td></tr>
        @endif
        @php($providerSignature = $request->verification === App\Domain\Signing\Verification\SignerCheck::Provider ? $request->providerSignature : null)
        @if($providerSignature)
            <tr><td class="muted">{{ $b('Underskrift hos extern tjänst') }}</td><td>{{ $providerSignature->provider_name }}</td></tr>
            @if($providerSignature->provider_service_name)
                <tr><td class="muted">{{ $b('Tjänstens namn enligt tjänsten') }}</td><td>{{ $providerSignature->provider_service_name }}</td></tr>
            @endif
            <tr><td class="muted">{{ $b('Certifikat') }}</td><td>{{ $providerSignature->certificate_name }}, {{ $b('utfärdat av :issuer', ['issuer' => (string) $providerSignature->certificate_issuer]) }}, {{ $b('serienummer :serial', ['serial' => (string) $providerSignature->certificate_serial]) }}</td></tr>
            <tr><td class="muted">{{ $b('Certifikatets kontrollsumma (SHA-256)') }}</td><td class="mono">{{ $providerSignature->certificate_sha256 }}</td></tr>
        @endif
        @if($request->consent_at)
            <tr><td class="muted">{{ $b('Samtycke till elektroniska handlingar') }}</td><td>{{ $b('Version :version, godkänt :time', ['version' => (string) $request->consent_version, 'time' => $time($request->consent_at)]) }}</td></tr>
            <tr><td class="muted">{{ $b('Samtyckestextens kontrollsumma (SHA-256)') }}</td><td class="mono">{{ $request->consent_text_sha256 }}</td></tr>
        @endif
        <tr><td class="muted">{{ $b('IP-adress') }}</td><td>{{ $request->signer_ip }}</td></tr>
        <tr><td class="muted">{{ $b('Webbläsare') }}</td><td class="mono">{{ $request->signer_user_agent }}</td></tr>
    </table>

    <div class="signature">
        @if($request->signature_method === App\Models\SigningRequest::DRAWN && $request->signature_image)
            <img src="data:image/png;base64,{{ $request->signature_image }}" alt="{{ $b('Namnteckning') }}">
        @else
            <span class="typed">{{ $request->signer_name }}</span>
        @endif
    </div>

    <h2>{{ $b('Händelser') }}</h2>
    <table class="meta">
        @foreach($request->events as $event)
            <tr><td class="muted" style="width: 38%">{{ $time($event->created_at) }}</td><td>{{ App\Domain\Signing\SigningPdfs::bilingualOf(fn () => $event->event->label()) }}</td></tr>
        @endforeach
    </table>

    <div class="box">
        <span class="muted">{!! $providerSignature ? App\Domain\Signing\SigningPdfs::bilingualBlock('Undertecknarens namnteckning och godkännande i NONOX är en enkel elektronisk underskrift. Den signerade PDF:en är oförändrad: räkna fram dess SHA-256 och jämför med kontrollsumman ovan för att se att det är samma dokument. Signeringen gjordes i NONOX via en personlig länk som skickades till e-postadressen ovan.') : ($request->channel === 'PORTAL' ? App\Domain\Signing\SigningPdfs::bilingualBlock('En enkel elektronisk underskrift. Den godkända PDF:en är oförändrad: räkna fram dess SHA-256 och jämför med kontrollsumman ovan för att se att det är samma dokument. Godkännandet gjordes i företagets kundportal i NONOX av en kontakt som hade loggat in med en engångskod till e-postadressen ovan.') : App\Domain\Signing\SigningPdfs::bilingualBlock('En enkel elektronisk underskrift. Den signerade PDF:en är oförändrad: räkna fram dess SHA-256 och jämför med kontrollsumman ovan för att se att det är samma dokument. Signeringen gjordes i NONOX via en personlig länk som skickades till e-postadressen ovan.')) !!}@if($request->verification->required()){!! App\Domain\Signing\SigningPdfs::bilingualBlockOf(fn () => __('Innan dokumentet visades kontrollerades undertecknaren med :method. Kontrollen minskar risken att någon annan använt en vidarebefordrad länk; underskriften är fortfarande en enkel elektronisk underskrift.', ['method' => mb_strtolower($request->verification->evidence())])) !!}@endif@if($providerSignature){!! App\Domain\Signing\SigningPdfs::bilingualBlockOf(fn () => __('Undertecknaren skrev dessutom under dokumentet hos :provider med certifikatet ovan. Den underskriften är en separat underskrift i den signerade PDF:en (PAdES) och kan kontrolleras i en PDF-läsare. NONOX anger inte någon nivå för den.', ['provider' => $providerSignature->provider_name])) !!}@endif</span>
    </div>
</body>
</html>
