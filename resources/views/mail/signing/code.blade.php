<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Din kod för att öppna :title', ['title' => $title]) }}</title>
</head>
<body style="margin: 0; padding: 24px 16px; background: #f5f5f4; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2933; line-height: 1.5;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; border-top: 6px solid {{ $color }};">
        <p style="margin: 0 0 4px; font-size: 13px; color: #616e7c;">{{ $companyName }}</p>
        <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __('Din kod') }}</h1>
        <p>{{ __('Skriv in koden på sidan där du öppnade :title.', ['title' => $title]) }}</p>
        <p style="margin: 24px 0; font-size: 32px; font-weight: 700; letter-spacing: 6px; font-family: 'SF Mono', Menlo, Consolas, monospace;">{{ $code }}</p>
        <p style="font-size: 13px; color: #616e7c;">{{ __('Koden gäller i :minutes minuter och kan användas en gång. Dela den inte med någon.', ['minutes' => $minutes]) }}</p>
        <p style="font-size: 13px; color: #616e7c;">{{ __('Bad du inte om någon kod? Då kan någon annan ha fått tag i din länk. Använd inte koden, och hör av dig till :company.', ['company' => $companyName]) }}</p>
        <p style="font-size: 12px; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 12px;">{{ $replyAddress ? __('Mejlet skickades av :company genom NONOX. Svarar du på det går svaret till :company.', ['company' => $companyName]) : __('Mejlet skickades av :company genom NONOX.', ['company' => $companyName]) }}</p>
    </div>
</body>
</html>
