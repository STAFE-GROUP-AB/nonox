<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 24px 16px; background: #f5f5f4; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2933; line-height: 1.5;">
    <div style="display: none; max-height: 0; overflow: hidden;">
        @if($kind === App\Mail\SigningEnvelopeMail::NOTICE)
            {{ __(':company har skickat :title för signering. Du får en kopia när alla har signerat.', ['company' => $companyName, 'title' => $title]) }}
        @elseif($kind === App\Mail\SigningEnvelopeMail::DECLINED)
            {{ __(':title avböjdes och blir inte giltigt.', ['title' => $title]) }}
        @else
            {{ __(':title är signerat av alla. Här är dokumentet med signeringsbevisen.', ['title' => $title]) }}
        @endif
    </div>
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px;">
        <p style="margin: 0 0 4px; font-size: 13px; color: #616e7c;">{{ $companyName }}</p>
        @if($kind === App\Mail\SigningEnvelopeMail::NOTICE)
            <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __(':title är skickat för signering', ['title' => $title]) }}</h1>
            <p>{{ __(':company har skickat dokumentet till :count personer för signering och har angett dig som kopiemottagare. Du behöver inte göra något.', ['company' => $companyName, 'count' => $signers]) }}</p>
            <p>{{ __('När alla har signerat får du dokumentet och signeringsbevisen i ett nytt mejl.') }}</p>
        @elseif($kind === App\Mail\SigningEnvelopeMail::DECLINED)
            <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __(':title avböjdes', ['title' => $title]) }}</h1>
            <p>{{ __('Du har signerat dokumentet, men en annan undertecknare avböjde det. Dokumentet blir därför inte giltigt, och ingen fler kan signera det.') }}</p>
            <p>{{ __('Kontakta :company om du har frågor.', ['company' => $companyName]) }}</p>
        @elseif($kind === App\Mail\SigningEnvelopeMail::COMPANY)
            <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __(':title är signerat av alla', ['title' => $title]) }}</h1>
            <p>{{ __('Alla :count undertecknare har signerat. Här är dokumentet som skickades och ett signeringsbevis per undertecknare.', ['count' => $signers]) }}</p>
            <p>{{ __('Signeringsbevisen visar vem som signerade, när och dokumentets kontrollsumma (SHA-256): :hash', ['hash' => $envelope->document_sha256]) }}</p>
            <p>{{ __('Spara filerna tillsammans.') }}</p>
        @else
            <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __(':title är signerat av alla', ['title' => $title]) }}</h1>
            <p>{{ __('Alla :count undertecknare har signerat. Här är dokumentet som skickades och en sammanställning med vem som signerade och när.', ['count' => $signers]) }}</p>
            <p>{{ __('Dokumentets kontrollsumma (SHA-256): :hash', ['hash' => $envelope->document_sha256]) }}</p>
            @if($toSigner)
                <p>{{ __('Ditt eget signeringsbevis fick du i ett tidigare mejl när du signerade.') }}</p>
            @endif
            <p>{{ __('Spara filerna tillsammans.') }}</p>
        @endif
        @if(in_array($kind, [App\Mail\SigningEnvelopeMail::NOTICE, App\Mail\SigningEnvelopeMail::COPY], true))
            {{-- TODO(legal-verify): ADR-046 gap 1 — whether this short art. 14 information is enough for copy recipients, and that the company as controller gives it. --}}
            <p style="font-size: 12px; color: #616e7c;" data-art14>{{ $toSigner
                ? __('Personuppgiftsansvarig är :controller. Du får mejlet för att du har signerat dokumentet. Sammanställningen visar undertecknarnas namn, titlar och tidpunkter och sparas så länge dokumentet sparas. Du har rätt att begära tillgång, rättelse och i vissa fall radering eller begränsning, och att klaga hos Integritetsskyddsmyndigheten (IMY).', ['controller' => $controller])
                : __('Personuppgiftsansvarig är :controller, som har angett din e-postadress (och eventuellt ditt namn) för att du ska få en kopia av dokumentet. Uppgifterna används bara för att skicka kopian och sparas så länge dokumentet sparas. Du har rätt att begära tillgång, rättelse och i vissa fall radering eller begränsning, och att klaga hos Integritetsskyddsmyndigheten (IMY).', ['controller' => $controller]) }}</p>
        @endif
        <p>{{ __('Med vänliga hälsningar') }}<br>{{ $companyName }}</p>
        <p style="font-size: 12px; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 12px;">{{ __('Mejlet skickades av :company genom NONOX.', ['company' => $companyName]) }}</p>
    </div>
</body>
</html>
