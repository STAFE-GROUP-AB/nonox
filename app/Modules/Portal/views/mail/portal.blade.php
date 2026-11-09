<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Kundportal') }}</title>
</head>
<body style="margin: 0; padding: 24px 16px; background: #f5f5f4; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2933; line-height: 1.5;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; border-top: 6px solid {{ $color }};">
        <p style="margin: 0 0 4px; font-size: 13px; color: #616e7c;">{{ $companyName }}</p>
        @if($kind === 'INVITATION')
            <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __('Välkommen till :company:s kundportal', ['company' => $companyName]) }}</h1>
            <p>{{ __('Hej :name!', ['name' => $extra['name'] ?? '']) }}</p>
            <p>{{ __(':inviter på :company har bjudit in dig till företagets kundportal för :customer. Där ser du fakturor, påminnelser och kontoutdrag, betalningsuppgifterna med OCR-nummer, och offerter. Du kan också ställa frågor direkt till :company.', ['inviter' => $extra['inviter'] ?? '', 'company' => $companyName, 'customer' => $extra['customer'] ?? '']) }}</p>
            <p>{{ __('Kundportalen är frivillig. Du får dina fakturor som förut, och vill du inte använda den kan du strunta i mejlet.') }}</p>
            <p style="margin: 24px 0;">
                <a href="{{ $url }}" style="display: inline-block; background: {{ $color }}; color: #ffffff; text-decoration: none; font-weight: 600; padding: 12px 20px; border-radius: 8px;">{{ __('Öppna inbjudan') }}</a>
            </p>
            <p style="font-size: 13px; color: #616e7c;">{{ __('Länken gäller i 14 dagar. När du öppnar den skickar vi en kod till den här adressen, så att bara du kan logga in.') }}</p>
            <p style="font-size: 13px; color: #616e7c;">{{ __(':company är personuppgiftsansvarig för dina uppgifter i kundportalen. Läs mer: :url', ['company' => $companyName, 'url' => $extra['privacy'] ?? '']) }}</p>
            <p style="font-size: 13px; color: #616e7c; word-break: break-all;">{{ __('Fungerar inte knappen? Kopiera adressen: :url', ['url' => $url]) }}</p>
        @elseif($kind === 'CODE')
            <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __('Din kod till kundportalen') }}</h1>
            <p style="font-size: 28px; font-weight: 700; letter-spacing: 4px; font-variant-numeric: tabular-nums;">{{ $code }}</p>
            <p>{{ __('Skriv in koden på sidan där du loggar in. Den gäller i 10 minuter och kan användas en gång.') }}</p>
            <p style="font-size: 13px; color: #616e7c;">{{ __('Har du inte bett om en kod kan du strunta i mejlet. Ingen kommer in utan koden.') }}</p>
        @elseif($kind === 'REPLY')
            <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __('Du har fått svar från :company', ['company' => $companyName]) }}</h1>
            <p>{{ __('Logga in i kundportalen för att läsa svaret. Av säkerhetsskäl står svaret inte i mejlet.') }}</p>
            <p style="margin: 24px 0;">
                <a href="{{ $url }}" style="display: inline-block; background: {{ $color }}; color: #ffffff; text-decoration: none; font-weight: 600; padding: 12px 20px; border-radius: 8px;">{{ __('Läs svaret') }}</a>
            </p>
        @else
            <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __('Din inloggning tas bort om 30 dagar') }}</h1>
            <p>{{ __('Du har inte loggat in i :company:s kundportal på ett år. Om du inte loggar in inom 30 dagar tar vi bort din inloggning och uppgifterna om den. Fakturorna påverkas inte.', ['company' => $companyName]) }}</p>
            <p style="margin: 24px 0;">
                <a href="{{ $url }}" style="display: inline-block; background: {{ $color }}; color: #ffffff; text-decoration: none; font-weight: 600; padding: 12px 20px; border-radius: 8px;">{{ __('Logga in') }}</a>
            </p>
        @endif
        <p>{{ __('Med vänliga hälsningar') }}<br>{{ $companyName }}</p>
        <p style="font-size: 12px; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 12px;">{{ $company?->email ? __('Mejlet skickades av :company genom NONOX. Svarar du på det går svaret till :company.', ['company' => $companyName]) : __('Mejlet skickades av :company genom NONOX.', ['company' => $companyName]) }}</p>
    </div>
</body>
</html>
