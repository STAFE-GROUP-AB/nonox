@php
    $b = fn (string $key, array $replace = []): string => App\Domain\Signing\SigningPdfs::bilingual($key, $replace);
    $zone = config('app.timezone');
    $time = fn ($value): string => $value === null ? '' : $value->setTimezone($zone)->format('Y-m-d H:i:s T');
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $b('Sammanställning av signeringen') }}</title>
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
                <h1>{{ $b('Sammanställning av signeringen') }}</h1>
                <span class="muted">{{ $title }}</span>
            </td>
        </tr>
    </table>

    <h2>{{ $b('Dokumentet som signerades') }}</h2>
    <table class="meta">
        <tr><td class="muted" style="width: 38%">{{ $b('Dokument') }}</td><td>{{ $title }} ({{ $envelope->document_name }})</td></tr>
        <tr><td class="muted">{{ $b('Kontrollsumma (SHA-256)') }}</td><td class="mono">{{ $envelope->document_sha256 }}</td></tr>
        <tr><td class="muted">{{ $b('Avsändare') }}</td><td>{{ $company->legal_name ?: $company->name }}@if($company->org_number) ({{ $company->org_number }})@endif</td></tr>
        <tr><td class="muted">{{ $b('Signerat av alla') }}</td><td>{{ $time($envelope->completed_at) }}</td></tr>
    </table>

    <h2>{{ $b('Undertecknare') }}</h2>
    <table class="meta">
        <tr><th class="muted">{{ $b('Steg') }}</th><th class="muted">{{ $b('Namn') }}</th><th class="muted">{{ $b('Signerad') }}</th><th class="muted">{{ $b('Sätt') }}</th></tr>
        @foreach($signers as $signer)
            <tr>
                <td>{{ $signer->step }}</td>
                <td>{{ $signer->signer_name }}@if($signer->signer_title), {{ $signer->signer_title }}@endif</td>
                <td>{{ $time($signer->signed_at) }}</td>
                <td>{{ $signer->signature_method === App\Models\SigningRequest::TYPED ? $b('Skrivet namn som underskrift') : $b('Ritad namnteckning') }}@if($signer->verification->required()) · {{ App\Domain\Signing\SigningPdfs::bilingualOf(fn () => $signer->verification->evidence()) }}@endif</td>
            </tr>
        @endforeach
    </table>

    @if(! empty($seal))
        <h2>{{ $b('Försegling') }}</h2>
        <table class="meta">
            <tr><td class="muted" style="width: 38%">{{ $b('Namn i certifikatet') }}</td><td>{{ $seal['name'] }}</td></tr>
            <tr><td class="muted">{{ $b('Utfärdare') }}</td><td>{{ $seal['selfSigned'] ? $b('Självsignerat certifikat – namnet är inte kontrollerat') : $seal['issuer'].' ('.$b('inte kontrollerat av NONOX').')' }}</td></tr>
            <tr><td class="muted">{{ $b('Certifikatets fingeravtryck (SHA-256)') }}</td><td class="mono">{{ $seal['fingerprint'] }}</td></tr>
        </table>

        <div class="box">
            <span class="muted">{!! App\Domain\Signing\SigningPdfs::bilingualBlock('Den här sidan lades till efter att alla hade signerat, och sedan förseglades hela filen med en elektronisk stämpel (PAdES). Stämpeln visar att filen inte har ändrats sedan den förseglades och med vilket certifikat den förseglades. Vem som står bakom certifikatet visar den bara om certifikatet är utfärdat av en utfärdare som PDF-läsaren litar på. Den är inte undertecknarnas underskrift: varje underskrift är en enkel elektronisk underskrift. Dokumentet som signerades finns oförändrat som den första versionen i filen, och kontrollsumman ovan gäller den versionen. Förseglingens nivå och en eventuell tidsstämpel visas i PDF-läsarens panel för underskrifter. Det fullständiga signeringsbeviset för varje underskrift finns hos avsändaren.') !!}</span>
            @if(($seal['source'] ?? null) === App\Domain\Signing\Seal\SealCredentials::INSTANCE)
                <span class="muted">{!! App\Domain\Signing\SigningPdfs::bilingualBlock('Stämpeln gjordes med certifikatet för den installation av NONOX som avsändaren använder. Den som driver installationen intygar inte dokumentets innehåll.') !!}</span>
            @endif
            @if($seal['selfSigned'])
                <span class="muted">{!! App\Domain\Signing\SigningPdfs::bilingualBlock('Stämpelns certifikat är självsignerat. PDF-läsare visar därför stämpeln som en underskrift med okänd giltighet; det betyder inte att filen har ändrats.') !!}</span>
            @endif
        </div>
    @else
        <div class="box">
            @if(! empty($withFields))
            <span class="muted">{!! App\Domain\Signing\SigningPdfs::bilingualBlock('En sammanställning för den som får en kopia. Varje underskrift är en enkel elektronisk underskrift. Undertecknarna fyllde i fält i dokumentet, så filen du har fått är dokumentet med deras värden ifyllda och har en annan kontrollsumma. Dokumentet som skickades finns oförändrat som den första versionen i filen, och kontrollsumman ovan gäller den versionen. Det fullständiga signeringsbeviset för varje underskrift finns hos avsändaren.') !!}</span>
            @else
            <span class="muted">{!! App\Domain\Signing\SigningPdfs::bilingualBlock('En sammanställning för den som får en kopia. Varje underskrift är en enkel elektronisk underskrift, och PDF:en är oförändrad: räkna fram dess SHA-256 och jämför med kontrollsumman ovan för att se att det är samma dokument. Det fullständiga signeringsbeviset för varje underskrift finns hos avsändaren.') !!}</span>
            @endif
        </div>
    @endif
</body>
</html>
