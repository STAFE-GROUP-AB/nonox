<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Ny sista dag att signera: :title', ['title' => $title]) }}</title>
</head>
<body style="margin: 0; padding: 24px 16px; background: #f5f5f4; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2933; line-height: 1.5;">
    <div style="display: none; max-height: 0; overflow: hidden;">{{ __('Sista dag att signera :title är nu :date.', ['title' => $title, 'date' => $expires->format('Y-m-d')]) }}</div>
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px; border-top: 6px solid {{ $color }};">
        <p style="margin: 0 0 4px; font-size: 13px; color: #616e7c;">{{ $companyName }}</p>
        <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __('Ny sista dag att signera') }}</h1>
        <p>{{ __('Hej!') }}</p>
        <p>{{ __(':company har ändrat sista dag att signera :title. Du kan signera till och med :date.', ['company' => $companyName, 'title' => $title, 'date' => $expires->format('Y-m-d')]) }}</p>
        <p style="font-size: 13px; color: #616e7c;">{{ __('Använd länken i mejlet du fick först, eller i nästa påminnelse. Har du redan signerat kan du bortse från det här mejlet.') }}</p>
        <p>{{ __('Med vänliga hälsningar') }}<br>{{ $companyName }}</p>
        <p style="font-size: 12px; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 12px;">{{ $company->email ? __('Mejlet skickades av :company genom NONOX. Svarar du på det går svaret till :company.', ['company' => $companyName]) : __('Mejlet skickades av :company genom NONOX.', ['company' => $companyName]) }}</p>
    </div>
</body>
</html>
