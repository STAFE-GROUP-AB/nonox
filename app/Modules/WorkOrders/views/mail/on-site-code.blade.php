<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 24px 16px; background: #f5f5f4; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2933; line-height: 1.5;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px;">
        <p style="margin: 0 0 4px; font-size: 13px; color: #616e7c;">{{ $companyName }}</p>
        <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __('Din kod: :code', ['code' => $code]) }}</h1>
        <p>{{ __('Skriv koden i telefonen som hantverkaren visar för att öppna :title och signera det. Koden gäller i 15 minuter.', ['title' => $title]) }}</p>
        <p>{{ __('Har du inte bett om en kod? Skriv inte in den någonstans, och hör av dig till :company.', ['company' => $companyName]) }}</p>
        <p style="font-size: 12px; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 12px;">{{ __('Mejlet skickades av :company genom NONOX.', ['company' => $companyName]) }}</p>
    </div>
</body>
</html>
