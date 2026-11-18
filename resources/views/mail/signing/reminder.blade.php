<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Påminnelse: :title', ['title' => $title]) }}</title>
</head>
<body style="margin: 0; padding: 24px 16px; background: #f5f5f4; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2933; line-height: 1.5;">
    <div style="display: none; max-height: 0; overflow: hidden;">{{ __(':title från :company väntar på din signering. Sista dag är :date.', ['title' => $title, 'company' => $companyName, 'date' => $expires->format('Y-m-d')]) }}</div>
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; border-top: 6px solid {{ $color }};">
        <p style="margin: 0 0 4px; font-size: 13px; color: #616e7c;">{{ $companyName }}</p>
        <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __('Påminnelse: :title att signera', ['title' => $title]) }}</h1>
        <p>{{ __('Hej!') }}</p>
        <p>{{ __(':title från :company väntar fortfarande på din signering. Öppna länken, läs igenom dokumentet och signera med din namnteckning. Du behöver inget konto.', ['title' => $title, 'company' => $companyName]) }}</p>
        <p style="margin: 24px 0;">
            <a href="{{ $url }}" style="display: inline-block; background: {{ $color }}; color: #ffffff; text-decoration: none; font-weight: 600; padding: 12px 20px; border-radius: 8px;">{{ __('Läs och signera') }}</a>
        </p>
        <p style="font-size: 13px; color: #616e7c;">{{ __('Sista dag att signera är :date. Länken är ny och personlig, och kan användas en gång. Har du redan signerat kan du bortse från det här mejlet.', ['date' => $expires->format('Y-m-d')]) }}</p>
        <p style="font-size: 13px; color: #616e7c; word-break: break-all;">{{ __('Fungerar inte knappen? Kopiera adressen: :url', ['url' => $url]) }}</p>
        <p>{{ __('Med vänliga hälsningar') }}<br>{{ $companyName }}</p>
        <p style="font-size: 12px; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 12px;">{{ $company->email ? __('Mejlet skickades av :company genom NONOX. Svarar du på det går svaret till :company.', ['company' => $companyName]) : __('Mejlet skickades av :company genom NONOX.', ['company' => $companyName]) }}</p>
    </div>
</body>
</html>
