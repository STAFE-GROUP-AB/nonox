<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 24px 16px; background: #f5f5f4; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2933; line-height: 1.5;">
    <div style="display: none; max-height: 0; overflow: hidden;">{{ __(':title är signerad. Här är din kopia med signeringsbeviset.', ['title' => $title]) }}</div>
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 28px;">
        <p style="margin: 0 0 4px; font-size: 13px; color: #616e7c;">{{ $companyName }}</p>
        <h1 style="margin: 0 0 16px; font-size: 22px;">{{ __(':title är signerad', ['title' => $title]) }}</h1>
        @if($toSigner)
            <p>{{ __('Tack! Här är din kopia av dokumentet du signerade och signeringsbeviset.') }}</p>
        @else
            <p>{{ __(':name signerade :title :time.', ['name' => (string) $request->signer_name, 'title' => $title, 'time' => $request->signed_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i')]) }}</p>
        @endif
        <p>{{ __('Signeringsbeviset visar vem som signerade, när och dokumentets kontrollsumma (SHA-256): :hash', ['hash' => $request->document_sha256]) }}</p>
        <p>{{ __('Spara båda filerna tillsammans.') }}</p>
        <p>{{ __('Med vänliga hälsningar') }}<br>{{ $companyName }}</p>
        <p style="font-size: 12px; color: #616e7c; border-top: 1px solid #e4e7eb; padding-top: 12px;">{{ __('Mejlet skickades av :company genom NONOX.', ['company' => $companyName]) }}</p>
    </div>
</body>
</html>
